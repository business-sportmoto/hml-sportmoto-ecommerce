<?php
declare(strict_types=1);

/**
 * app/services/payment/PagamentoErroClassifier.php
 *
 * Traduz a resposta de uma adquirente na porta que o grafo deve seguir.
 * Único lugar do sistema que decide "isto pode cair para outra adquirente?".
 *
 * POLÍTICA VIGENTE — OPÇÃO 2:
 *   Fallback SÓ quando a transação não foi julgada pelo emissor. Negativa de
 *   emissor encerra o fluxo, qualquer que seja o motivo.
 *
 *   Motivo: trocar de adquirente NÃO cria uma transação nova aos olhos da
 *   bandeira. Visa e Mastercard monitoram cartão + estabelecimento + valor,
 *   não quem processou. Reenviar um cartão negado por outra adquirente conta
 *   como retentativa e entra nos programas Excessive Reattempts (Visa) e TPE
 *   (Mastercard), com tarifa por tentativa indevida.
 *
 *   Roteamento por parcelas/valor/bandeira NÃO é afetado: aquilo é decisão
 *   tomada ANTES da autorização e não tem restrição nenhuma.
 *
 * CERTEZA × INCERTEZA:
 *   Falha técnica se divide em duas. Conexão recusada, DNS e 502/503/504 são
 *   certeza de que nada foi processado — cai para a próxima na hora. Timeout
 *   e 500 são incerteza: a autorização pode ter passado e a resposta ter se
 *   perdido. Nesses casos o motor precisa CONSULTAR a adquirente antes de
 *   qualquer outra coisa, senão cobra o cliente duas vezes.
 */
class PagamentoErroClassifier
{
    // =========================================================================
    // CAMADA DE TRANSPORTE — rede e HTTP, antes de olhar o corpo
    // =========================================================================

    /**
     * Classifica falhas que impedem ler um resultado de autorização.
     * Devolve null quando a resposta chegou íntegra e deve ser interpretada
     * pela camada de autorização.
     *
     * @param array $resp Saída de SafraPayClient::chamar()
     */
    public static function porTransporte(array $resp): ?PagamentoClassificacao
    {
        $c = new PagamentoClassificacao();
        $c->httpStatus = (int) ($resp['http'] ?? 0);
        $c->duracaoMs  = (int) ($resp['duracao_ms'] ?? 0);
        $c->traceKey   = $resp['traceKey'] ?? null;

        $erroRede = $resp['erro_rede'] ?? null;

        // ── Sem resposta ────────────────────────────────────────────────
        if ($erroRede !== null) {
            $e = mb_strtolower((string) $erroRede);

            // Não conectou: a requisição nunca chegou. Certeza de que nada
            // foi processado — pode cair para a próxima imediatamente.
            $naoConectou = str_contains($e, 'could not resolve')
                        || str_contains($e, 'couldn\'t resolve')
                        || str_contains($e, 'connection refused')
                        || str_contains($e, 'failed to connect')
                        || str_contains($e, 'ssl connect error')
                        || str_contains($e, 'connect() timed out');

            if ($naoConectou) {
                $c->porta             = PagamentoClassificacao::INDISPONIVEL;
                $c->classeErro        = 'indisponivel';
                $c->podeCairParaOutra = true;
                $c->mensagemAdquirente = 'rede: ' . $erroRede;
                $c->mensagemCliente   = 'Estamos com instabilidade no pagamento. Tentando outra opção...';
                return $c;
            }

            // Conectou e não respondeu a tempo: PODE ter autorizado.
            $c->porta              = PagamentoClassificacao::INCERTO;
            $c->classeErro         = 'timeout';
            $c->podeCairParaOutra  = false;   // só depois da consulta
            $c->exigeConsulta      = true;
            $c->mensagemAdquirente = 'rede: ' . $erroRede;
            $c->mensagemCliente    = 'Estamos confirmando seu pagamento. Aguarde alguns instantes.';
            return $c;
        }

        $http = $c->httpStatus;

        // ── HTTP 0: curl devolveu sem erro e sem status (raro) ──────────
        if ($http === 0) {
            $c->porta             = PagamentoClassificacao::INCERTO;
            $c->classeErro        = 'timeout';
            $c->exigeConsulta     = true;
            $c->mensagemCliente   = 'Estamos confirmando seu pagamento. Aguarde alguns instantes.';
            return $c;
        }

        // ── Indisponibilidade de borda: não chegou a processar ──────────
        if (in_array($http, [429, 502, 503, 504], true)) {
            $c->porta             = PagamentoClassificacao::INDISPONIVEL;
            $c->classeErro        = $http === 429 ? 'rate_limit' : 'indisponivel';
            $c->podeCairParaOutra = true;
            $c->mensagemCliente   = 'Estamos com instabilidade no pagamento. Tentando outra opção...';
            return $c;
        }

        // ── 500: a aplicação da adquirente quebrou. Pode ter autorizado
        //    antes de quebrar — conservador, exige consulta.
        if ($http === 500) {
            $c->porta           = PagamentoClassificacao::INCERTO;
            $c->classeErro      = 'erro_adquirente';
            $c->exigeConsulta   = true;
            $c->mensagemCliente = 'Estamos confirmando seu pagamento. Aguarde alguns instantes.';
            return $c;
        }

        // ── Credencial nossa inválida/expirada ──────────────────────────
        // Cai para outra adquirente: o problema é a nossa integração com
        // ESTA, e a próxima pode estar com credencial boa. Também vira
        // alerta — é configuração quebrada, não acaso.
        if (in_array($http, [401, 403], true)) {
            $c->porta             = PagamentoClassificacao::ERRO_TECNICO;
            $c->classeErro        = 'credencial';
            $c->podeCairParaOutra = true;
            $c->mensagemCliente   = 'Estamos com instabilidade no pagamento. Tentando outra opção...';
            return $c;
        }

        // ── 4xx de contrato: payload recusado na validação ──────────────
        // Nada foi processado, então cair para a próxima é seguro. Mas
        // costuma ser bug nosso, e o log precisa dizer isso.
        if ($http >= 400 && $http < 500) {
            $c->porta             = PagamentoClassificacao::ERRO_TECNICO;
            $c->classeErro        = 'contrato';
            $c->podeCairParaOutra = true;
            $c->mensagemCliente   = 'Não foi possível processar o pagamento. Tente novamente.';
            return $c;
        }

        // Resposta íntegra — quem decide é a camada de autorização.
        return null;
    }

    // =========================================================================
    // CAMADA DE AUTORIZAÇÃO — ABECS
    // =========================================================================

    /**
     * Mapa de authorizationResponseCode (padrão ABECS) → classe canônica.
     *
     * Montado a partir da tabela Visa de /primeiros-passos#codigos-de-retorno-abecs
     * da Safra, que cobre os códigos ISO comuns às bandeiras. A documentação
     * pede mapear código + bandeira; refinamentos por bandeira entram aqui
     * conforme aparecerem em produção.
     *
     * IMPORTANTE: na opção 2, `reversivel` é apenas REGISTRADO. Nenhum destes
     * casos dispara outra adquirente — a porta é sempre um negado_*.
     *
     * [classe, reversível, mensagem ao cliente]
     */
    private const ABECS = [
        '00' => ['aprovado',           null,  'Pagamento aprovado.'],
        '03' => ['config_lojista',     false, 'Não foi possível processar o pagamento. Fale com a loja.'],
        '05' => ['generico',           true,  'Pagamento não autorizado. Contate o banco emissor do cartão.'],
        '06' => ['cartao_invalido',    false, 'Verifique os dados do cartão e tente novamente.'],
        '12' => ['formato',            false, 'Não foi possível processar o cartão. Tente outro cartão.'],
        '13' => ['valor_invalido',     false, 'Valor não permitido para este cartão.'],
        '41' => ['cartao_bloqueado',   false, 'Cartão não permitido para esta compra. Contate o banco emissor.'],
        '43' => ['cartao_bloqueado',   false, 'Cartão não permitido para esta compra. Contate o banco emissor.'],
        '51' => ['saldo_insuficiente', true,  'Saldo ou limite insuficiente no cartão.'],
        '54' => ['cartao_vencido',     false, 'Cartão vencido. Verifique a data de validade.'],
        '55' => ['senha_invalida',     true,  'Senha inválida.'],
        '57' => ['nao_permitida',      false, 'Transação não permitida para este cartão.'],
        '58' => ['nao_permitida',      false, 'Transação não permitida para este cartão.'],
        '59' => ['antifraude',         true,  'Pagamento não autorizado. Contate o banco emissor do cartão.'],
        '61' => ['valor_excedido',     true,  'Valor excede o limite do cartão.'],
        '62' => ['nao_permitida',      false, 'Cartão não permite este tipo de transação.'],
        '65' => ['valor_excedido',     true,  'Limite de transações excedido. Contate o banco emissor.'],
        '74' => ['senha_invalida',     false, 'Senha inválida.'],
        '75' => ['senha_invalida',     true,  'Excedidas as tentativas de senha. Contate o banco emissor.'],
        '81' => ['senha_invalida',     false, 'Senha inválida.'],
        '86' => ['senha_invalida',     true,  'Senha inválida.'],
        'N4' => ['valor_excedido',     true,  'Valor excede o limite do cartão.'],
        // 92 observado em recusa real do simulador (Elo, R$ 3,33): emissor
        // não localizado no roteamento da rede. Continua sendo julgamento —
        // não libera outra adquirente.
        '92' => ['emissor_indisponivel', true, 'Pagamento não autorizado. Tente novamente ou use outro cartão.'],
    ];

    /** Classe canônica → porta do nó. */
    private const PORTA_POR_CLASSE = [
        'saldo_insuficiente' => PagamentoClassificacao::NEGADO_SALDO,
        'valor_excedido'     => PagamentoClassificacao::NEGADO_SALDO,
        'antifraude'         => PagamentoClassificacao::NEGADO_ANTIFRAUDE,
        'cartao_invalido'    => PagamentoClassificacao::NEGADO_DADOS,
        'cartao_vencido'     => PagamentoClassificacao::NEGADO_DADOS,
        'cartao_bloqueado'   => PagamentoClassificacao::NEGADO_DADOS,
        'senha_invalida'     => PagamentoClassificacao::NEGADO_DADOS,
    ];

    /**
     * Classifica o resultado de uma autorização já recebida da Safra.
     *
     * @param array $charge charge da resposta (chargeStatus, transactions[])
     */
    public static function porAutorizacaoSafra(array $charge, array $resp = []): PagamentoClassificacao
    {
        $c = new PagamentoClassificacao();
        $c->httpStatus = (int) ($resp['http'] ?? 200);
        $c->duracaoMs  = (int) ($resp['duracao_ms'] ?? 0);
        $c->traceKey   = $resp['traceKey'] ?? null;
        $c->chargeId   = $charge['chargeId'] ?? ($charge['id'] ?? null);

        $tx = $charge['transactions'][0] ?? [];
        $codigo = isset($tx['authorizationResponseCode'])
            ? strtoupper(trim((string) $tx['authorizationResponseCode']))
            : null;

        $c->codigoAdquirente   = $codigo;
        // A Safra devolve a bandeira aninhada em card.brand (string, ex.:
        // "Mastercard"); o nível da transação não a traz. Ler só $tx['brand']
        // deixava a coluna vazia no dashboard.
        $c->bandeira           = $tx['card']['brand'] ?? ($tx['brand'] ?? null);
        $c->merchantAdviceCode = isset($tx['merchantAdviceCode']) && $tx['merchantAdviceCode'] !== ''
            ? (string) $tx['merchantAdviceCode']
            : null;

        $status = (string) ($tx['transactionStatus'] ?? ($charge['chargeStatus'] ?? ''));

        // ── Cancelada/estornada — ANTES da checagem de aprovação ────────
        // Uma cobrança estornada CONTINUA com authorizationResponseCode 00:
        // aquele código descreve a autorização original, que de fato foi
        // aprovada. Checar o 00 primeiro fazia a reconsulta de um pedido já
        // estornado voltar como "aprovado" — dinheiro devolvido e pedido
        // liberado ao mesmo tempo.
        if (!empty($tx['isCanceled']) || in_array($status, ['Canceled', 'Reversed', 'Refunded'], true)) {
            $c->porta           = PagamentoClassificacao::NEGADO_GENERICO;
            $c->classeErro      = 'cancelado';
            $c->mensagemCliente = 'Esta cobrança foi cancelada.';
            return $c;
        }

        if ($status === 'PendingCancel') {
            $c->porta                = PagamentoClassificacao::PENDENTE;
            $c->classeErro           = 'cancelamento_pendente';
            $c->cancelamentoPendente = true;
            $c->mensagemCliente      = 'Cancelamento em processamento.';
            return $c;
        }

        // Pix/boleto emitidos e ainda não pagos, vistos por reconsulta.
        if ($status === 'PendingPayment') {
            $c->porta           = PagamentoClassificacao::PENDENTE;
            $c->classeErro      = 'aguardando_pagamento';
            $c->mensagemCliente = 'Aguardando a confirmação do pagamento.';
            return $c;
        }

        // ── Aprovada ────────────────────────────────────────────────────
        if ($codigo === '00' || in_array($status, ['Authorized', 'Captured', 'Paid'], true)) {
            $c->porta            = PagamentoClassificacao::APROVADO;
            $c->classeErro       = 'aprovado';
            $c->mensagemCliente  = 'Pagamento aprovado.';
            $c->reversivel       = null;
            return $c;
        }

        // ── Negada ──────────────────────────────────────────────────────
        [$classe, $reversivel, $msg] = self::ABECS[$codigo]
            ?? ['generico', null, 'Pagamento não autorizado. Contate o banco emissor do cartão.'];

        $c->classeErro         = $classe;
        $c->reversivel         = $reversivel;
        $c->mensagemCliente    = $msg;
        $c->mensagemAdquirente = self::textoNegativa($tx, $charge);
        $c->porta              = self::PORTA_POR_CLASSE[$classe]
                                 ?? PagamentoClassificacao::NEGADO_GENERICO;

        // OPÇÃO 2: emissor respondeu, o fluxo para aqui. Não existe caminho
        // que ligue uma porta negado_* em outra adquirente — o motor recusa
        // essa aresta na validação do grafo.
        $c->podeCairParaOutra = false;

        return $c;
    }

    /**
     * Classifica a CRIAÇÃO de uma cobrança Pix ou boleto.
     *
     * Diferente do cartão, aqui não existe emissor julgando: a Safra devolve
     * chargeStatus=PreAuthorized e transactionStatus=PendingPayment junto com
     * o instrumento (QR code ou linha digitável). O sucesso desta etapa é ter
     * produzido algo pagável — o dinheiro chega depois, por webhook.
     */
    public static function porCriacaoCobranca(array $charge, array $resp = [], string $metodo = 'pix'): PagamentoClassificacao
    {
        $c = new PagamentoClassificacao();
        $c->httpStatus = (int) ($resp['http'] ?? 200);
        $c->duracaoMs  = (int) ($resp['duracao_ms'] ?? 0);
        $c->traceKey   = $resp['traceKey'] ?? null;
        $c->chargeId   = $charge['id'] ?? ($charge['chargeId'] ?? null);

        $tx     = $charge['transactions'][0] ?? [];
        $status = (string) ($tx['transactionStatus'] ?? ($charge['chargeStatus'] ?? ''));

        if ($metodo === 'pix') {
            $c->pixQrCode       = $tx['qrCode']       ?? null;
            $c->pixQrCodeBase64 = $tx['qrCodeBase64'] ?? null;
            $c->pixExpiraEm     = $tx['expirationDateTime'] ?? ($tx['dueDate'] ?? null);
        } else {
            $c->boletoLinhaDigitavel = $tx['digitalLine'] ?? null;
            $c->boletoCodigoBarras   = $tx['barcode']     ?? null;
            $c->boletoUrl            = $tx['bankSlipUrl'] ?? null;
            $c->boletoVencimento     = $tx['deadline']    ?? null;
        }

        // Já pago (raro na criação, possível em reconsulta).
        if (in_array($status, ['Captured', 'Paid', 'Authorized'], true)) {
            $c->porta           = PagamentoClassificacao::APROVADO;
            $c->classeErro      = 'aprovado';
            $c->mensagemCliente = 'Pagamento confirmado.';
            return $c;
        }

        // Instrumento emitido: o cliente tem o que pagar.
        $temInstrumento = $metodo === 'pix'
            ? !empty($c->pixQrCode)
            : !empty($c->boletoLinhaDigitavel);

        if ($temInstrumento || $status === 'PendingPayment' || $status === 'PreAuthorized') {
            $c->porta           = PagamentoClassificacao::PENDENTE;
            $c->classeErro      = 'aguardando_pagamento';
            $c->mensagemCliente = $metodo === 'pix'
                ? 'Pix gerado. Finalize o pagamento no seu banco.'
                : 'Boleto gerado. Pague até o vencimento.';
            return $c;
        }

        // Emissão recusada pelo emissor do instrumento (banco do boleto, PSP
        // do Pix) ou nenhum instrumento devolvido. Nada foi cobrado, então
        // cair para outra adquirente é seguro e desejável.
        //
        // Causa comum em homologação: merchant sem emissor habilitado — a API
        // aceita o payload (success:true, errors vazio) e o simulador devolve
        // Denied/Rejected com bankIssuerId "Simulator". Não é erro de
        // integração; é configuração do estabelecimento na adquirente.
        $c->porta             = PagamentoClassificacao::ERRO_TECNICO;
        $c->classeErro        = in_array($status, ['Denied', 'NotAuthorized'], true)
                                ? 'emissao_recusada'
                                : 'sem_instrumento';
        $c->podeCairParaOutra = true;
        $c->mensagemAdquirente = 'status: ' . ($status ?: 'vazio')
                               . (!empty($tx['status'])       ? ' / ' . $tx['status'] : '')
                               . (!empty($tx['bankIssuerId']) ? ' / emissor: ' . $tx['bankIssuerId'] : '');
        $c->mensagemCliente   = 'Não foi possível gerar a cobrança. Tente outra forma de pagamento.';
        return $c;
    }

    /**
     * Só o motor pode decidir cair para outra adquirente, e só através daqui.
     * Centralizar a permissão num único ponto impede que uma configuração de
     * fluxo mal montada no Drawflow crie retentativa de negativa sem querer.
     */
    public static function permiteFallback(PagamentoClassificacao $c): bool
    {
        if ($c->exigeConsulta)     return false;  // consulta primeiro
        if ($c->houveJulgamento()) return false;  // emissor respondeu → fim
        return $c->podeCairParaOutra;
    }

    private static function textoNegativa(array $tx, array $charge): ?string
    {
        foreach (['authorizationResponseMessage', 'message', 'transactionStatus'] as $k) {
            if (!empty($tx[$k]) && is_scalar($tx[$k])) return mb_substr((string) $tx[$k], 0, 255);
        }
        if (!empty($charge['chargeStatus'])) return mb_substr((string) $charge['chargeStatus'], 0, 255);
        return null;
    }
}
