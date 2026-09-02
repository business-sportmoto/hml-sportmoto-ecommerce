<?php
declare(strict_types=1);

// ════════════════════════════════════════════════════════
// app/services/NewsletterService.php
//
// Inscrição na newsletter com confirmação por código e cupom de boas-vindas.
//
// ── Por que existe o código ───────────────────────────────────────────────
// Antes, o formulário do rodapé gravava qualquer endereço digitado. Com um
// cupom no fim do fluxo isso vira fraude barata: inventar e-mails gera
// descontos. O código prova que a caixa de entrada é de quem está pedindo, e
// o cupom só nasce depois disso.
//
// ── O que impede o formulário de virar arma ──────────────────────────────
// Um endpoint que dispara e-mail para um endereço informado por terceiros é
// um relay de spam se não tiver freio. Três freios, em camadas:
//   por e-mail  — 1 código a cada 60s, no máximo 5 por dia
//   por IP      — 8 cadastros por hora
//   supressão   — endereço que já deu bounce ou reclamou nunca recebe
// ════════════════════════════════════════════════════════

class NewsletterService
{
    /** Validade do código. Curto o bastante para não virar credencial parada. */
    public const CODIGO_MINUTOS = 15;

    /** Erros de digitação antes de o código morrer — evita força bruta em 6 dígitos. */
    public const MAX_TENTATIVAS = 5;

    public const REENVIO_SEGUNDOS = 60;
    public const MAX_ENVIOS_DIA   = 5;
    public const MAX_POR_IP_HORA  = 8;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getConnection();
    }

    /* =================================================================
       CONFIGURAÇÃO DO CUPOM
       ================================================================= */

    /**
     * Como é o cupom de boas-vindas. Editado em Configurações → Rodapé.
     *
     * Fica em `configuracoes` e não em código porque é decisão comercial: o
     * valor do desconto muda com a margem, e mudar margem não pode exigir
     * deploy.
     */
    public static function configCupom(): array
    {
        $tipo = (string) ConfigHelper::get('footer_newsletter_cupom_tipo', 'fixo');

        return [
            'ativo'    => (bool) ConfigHelper::get('footer_newsletter_cupom_ativo', '1'),
            'tipo'     => in_array($tipo, ['percentual', 'fixo'], true) ? $tipo : 'fixo',
            'valor'    => (float) str_replace(',', '.', (string) ConfigHelper::get('footer_newsletter_cupom_valor', '10')),
            'minimo'   => (float) str_replace(',', '.', (string) ConfigHelper::get('footer_newsletter_cupom_minimo', '0')),
            'dias'     => max(1, (int) ConfigHelper::get('footer_newsletter_cupom_dias', '30')),
            'prefixo'  => strtoupper(preg_replace('/[^A-Za-z0-9]/', '',
                              (string) ConfigHelper::get('footer_newsletter_cupom_prefixo', 'BV')) ?: 'BV'),
        ];
    }

    /** Texto curto do benefício, para a tela ("R$ 10 de desconto"). */
    public static function descricaoCupom(): string
    {
        $c = self::configCupom();
        if (!$c['ativo'] || $c['valor'] <= 0) return '';

        return $c['tipo'] === 'percentual'
            ? rtrim(rtrim(number_format($c['valor'], 2, ',', '.'), '0'), ',') . '% de desconto'
            : 'R$ ' . number_format($c['valor'], 2, ',', '.') . ' de desconto';
    }

    /* =================================================================
       ETAPA 1 — PEDIR O CÓDIGO
       ================================================================= */

    public function solicitarCodigo(string $email, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if (!SecurityHelper::validateEmail($email)) {
            return ['ok' => false, 'msg' => 'E-mail inválido.'];
        }

        // Endereço que já deu bounce ou marcou spam não recebe mais nada —
        // insistir queima a reputação do domínio de envio para todo mundo.
        try {
            if ((new EmailSuppressionService())->isSuppressed($email)) {
                return ['ok' => false, 'msg' => 'Não conseguimos enviar e-mails para este endereço.'];
            }
        } catch (Throwable $e) {
            LogService::exception($e, 'warning', 'app', ['onde' => 'NewsletterService::supressao']);
        }

        if (($erro = $this->freioPorIp($ip)) !== null) return $erro;

        $inscrito = $this->porEmail($email);

        // Já confirmado e com cupom válido: devolve o mesmo cupom em vez de
        // mandar outro código. Reconhecer que a pessoa já é assinante é melhor
        // UX do que fingir que não — e o que "vaza" é alguém assinar uma
        // newsletter de loja, o que não é segredo de ninguém.
        if ($inscrito && $inscrito['status'] === 'confirmado') {
            $cupom = $this->cupomValido((string) ($inscrito['cupom_codigo'] ?? ''));
            return $cupom
                ? ['ok' => true, 'etapa' => 'ja_inscrito', 'cupom' => $cupom,
                   'msg' => 'Você já está inscrito — seu cupom continua valendo.']
                : ['ok' => true, 'etapa' => 'ja_inscrito',
                   'msg' => 'Você já está inscrito na nossa newsletter.'];
        }

        if ($inscrito && ($erro = $this->freioPorEmail($inscrito)) !== null) return $erro;

        // 6 dígitos, gerados com fonte criptográfica. rand() aqui seria
        // adivinhável a partir de dois códigos observados.
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $this->gravarCodigo($email, $codigo, $ip, $inscrito);
        } catch (Throwable $e) {
            LogService::exception($e, 'error', 'app', ['onde' => 'NewsletterService::gravarCodigo']);
            return ['ok' => false, 'msg' => 'Não foi possível iniciar a inscrição agora.'];
        }

        if (!$this->enviarCodigo($email, $codigo)) {
            return ['ok' => false, 'msg' => 'Não conseguimos enviar o e-mail agora. Tente em alguns minutos.'];
        }

        return [
            'ok'      => true,
            'etapa'   => 'codigo',
            'msg'     => 'Enviamos um código para ' . $this->mascarar($email) . '.',
            'email'   => $email,
            'minutos' => self::CODIGO_MINUTOS,
        ];
    }

    /* =================================================================
       ETAPA 2 — CONFIRMAR
       ================================================================= */

    public function confirmar(string $email, string $nome, string $codigo, string $ip): array
    {
        $email  = mb_strtolower(trim($email));
        $nome   = trim(strip_tags($nome));
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';

        if ($nome === '')          return ['ok' => false, 'msg' => 'Informe seu nome.'];
        if (mb_strlen($nome) > 120) return ['ok' => false, 'msg' => 'Nome muito longo.'];
        if (strlen($codigo) !== 6)  return ['ok' => false, 'msg' => 'O código tem 6 dígitos.'];

        $inscrito = $this->porEmail($email);
        if (!$inscrito || empty($inscrito['codigo_hash'])) {
            return ['ok' => false, 'msg' => 'Peça um código novo.'];
        }

        if ($inscrito['status'] === 'confirmado') {
            $cupom = $this->cupomValido((string) ($inscrito['cupom_codigo'] ?? ''));
            return ['ok' => true, 'cupom' => $cupom, 'msg' => 'Você já estava inscrito.'];
        }

        if (strtotime((string) $inscrito['codigo_expira_em']) < time()) {
            return ['ok' => false, 'msg' => 'O código expirou. Peça um novo.'];
        }

        if ((int) $inscrito['codigo_tentativas'] >= self::MAX_TENTATIVAS) {
            $this->invalidarCodigo((int) $inscrito['id']);
            return ['ok' => false, 'msg' => 'Tentativas demais. Peça um código novo.'];
        }

        if (!password_verify($codigo, (string) $inscrito['codigo_hash'])) {
            $this->db->prepare("UPDATE newsletter SET codigo_tentativas = codigo_tentativas + 1 WHERE id = ?")
                     ->execute([$inscrito['id']]);
            $restam = self::MAX_TENTATIVAS - ((int) $inscrito['codigo_tentativas'] + 1);
            return ['ok' => false, 'msg' => $restam > 0
                ? "Código incorreto. Restam {$restam} tentativas."
                : 'Código incorreto. Peça um novo.'];
        }

        // Cupom antes do commit: se a criação falhar, ninguém fica confirmado
        // sem o desconto que foi prometido na tela.
        try {
            $this->db->beginTransaction();

            $cupom = $this->gerarCupom($email, $nome);

            $this->db->prepare(
                "UPDATE newsletter
                    SET nome = :nome, status = 'confirmado', ativo = 1,
                        confirmado_em = NOW(), cupom_codigo = :cupom,
                        codigo_hash = NULL, codigo_expira_em = NULL, codigo_tentativas = 0
                  WHERE id = :id"
            )->execute([':nome' => $nome, ':cupom' => $cupom['codigo'] ?? null, ':id' => $inscrito['id']]);

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            LogService::exception($e, 'error', 'app', ['onde' => 'NewsletterService::confirmar', 'email' => $email]);
            return ['ok' => false, 'msg' => 'Confirmamos seu e-mail, mas falhou ao gerar o cupom. Fale com a gente.'];
        }

        LogService::info('Newsletter confirmada', ['email' => $email, 'cupom' => $cupom['codigo'] ?? null]);

        return ['ok' => true, 'msg' => 'Inscrição confirmada!', 'cupom' => $cupom, 'nome' => $nome];
    }

    /* =================================================================
       CUPOM
       ================================================================= */

    /**
     * Cria um cupom de uso único para quem acabou de confirmar.
     *
     * `limite_total = 1` e não só `limite_por_cliente`: a pessoa pode nem ter
     * conta ainda, então não há cliente para limitar. Um código que só funciona
     * uma vez no site inteiro resolve sem depender de cadastro.
     */
    private function gerarCupom(string $email, string $nome): ?array
    {
        $c = self::configCupom();
        if (!$c['ativo'] || $c['valor'] <= 0) return null;

        $codigo = $this->codigoInedito($c['prefixo']);
        $fim    = date('Y-m-d H:i:s', strtotime('+' . $c['dias'] . ' days'));

        $this->db->prepare(
            "INSERT INTO cupons
                (codigo, nome, descricao, tipo, valor, valor_minimo_pedido, ativo,
                 data_inicio, data_fim, limite_total, limite_por_cliente,
                 campanha_nome, criado_em, atualizado_em)
             VALUES
                (:codigo, :nome, :desc, :tipo, :valor, :minimo, 1,
                 NOW(), :fim, 1, 1, 'Newsletter', NOW(), NOW())"
        )->execute([
            ':codigo' => $codigo,
            ':nome'   => 'Boas-vindas newsletter',
            ':desc'   => 'Gerado na inscrição da newsletter por ' . $email,
            ':tipo'   => $c['tipo'],
            ':valor'  => $c['valor'],
            ':minimo' => $c['minimo'],
            ':fim'    => $fim,
        ]);

        return [
            'codigo'    => $codigo,
            'descricao' => self::descricaoCupom(),
            'minimo'    => $c['minimo'],
            'validade'  => date('d/m/Y', strtotime($fim)),
        ];
    }

    /** Código curto e sem ambiguidade visual — quem digita não confunde O e 0. */
    private function codigoInedito(string $prefixo): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $st = $this->db->prepare("SELECT id FROM cupons WHERE codigo = ? LIMIT 1");

        for ($i = 0; $i < 12; $i++) {
            $sufixo = '';
            for ($j = 0; $j < 5; $j++) $sufixo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];

            $codigo = $prefixo . $sufixo;
            $st->execute([$codigo]);
            if (!$st->fetchColumn()) return $codigo;
        }

        // 32^5 combinações: chegar aqui significa colisão 12 vezes seguidas, o
        // que na prática é o alfabeto ter mudado. O timestamp garante saída.
        return $prefixo . strtoupper(base_convert((string) time(), 10, 32));
    }

    /** O cupom ainda existe, está ativo e no prazo? */
    private function cupomValido(string $codigo): ?array
    {
        if ($codigo === '') return null;

        $st = $this->db->prepare(
            "SELECT codigo, tipo, valor, valor_minimo_pedido, data_fim, total_usos, limite_total
               FROM cupons
              WHERE codigo = ? AND ativo = 1 AND deleted_at IS NULL
                AND (data_fim IS NULL OR data_fim > NOW())
              LIMIT 1"
        );
        $st->execute([$codigo]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        if ($c['limite_total'] !== null && (int) $c['total_usos'] >= (int) $c['limite_total']) return null;

        return [
            'codigo'    => $c['codigo'],
            'descricao' => $c['tipo'] === 'percentual'
                ? rtrim(rtrim(number_format((float) $c['valor'], 2, ',', '.'), '0'), ',') . '% de desconto'
                : 'R$ ' . number_format((float) $c['valor'], 2, ',', '.') . ' de desconto',
            'minimo'   => (float) $c['valor_minimo_pedido'],
            'validade' => $c['data_fim'] ? date('d/m/Y', strtotime((string) $c['data_fim'])) : null,
        ];
    }

    /* =================================================================
       INTERNO
       ================================================================= */

    private function porEmail(string $email): ?array
    {
        $st = $this->db->prepare("SELECT * FROM newsletter WHERE email = ? LIMIT 1");
        $st->execute([$email]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function gravarCodigo(string $email, string $codigo, string $ip, ?array $inscrito): void
    {
        $hash   = password_hash($codigo, PASSWORD_DEFAULT);
        $expira = date('Y-m-d H:i:s', time() + self::CODIGO_MINUTOS * 60);
        $hoje   = date('Y-m-d');

        if ($inscrito) {
            $this->db->prepare(
                "UPDATE newsletter
                    SET codigo_hash = :h, codigo_expira_em = :e, codigo_tentativas = 0,
                        codigo_enviado_em = NOW(), ip = :ip,
                        envios_dia = IF(envios_data = :hoje, envios_dia + 1, 1),
                        envios_data = :hoje2
                  WHERE id = :id"
            )->execute([':h' => $hash, ':e' => $expira, ':ip' => $ip,
                        ':hoje' => $hoje, ':hoje2' => $hoje, ':id' => $inscrito['id']]);
            return;
        }

        $this->db->prepare(
            "INSERT INTO newsletter
                (email, nome, status, codigo_hash, codigo_expira_em, codigo_enviado_em,
                 envios_dia, envios_data, ip, ativo, token_cancelamento, criado_em)
             VALUES (:email, '', 'pendente', :h, :e, NOW(), 1, :hoje, :ip, 0, :token, NOW())"
        )->execute([
            ':email' => $email, ':h' => $hash, ':e' => $expira,
            ':hoje' => $hoje, ':ip' => $ip,
            ':token' => SecurityHelper::generateToken(16),
        ]);
    }

    private function invalidarCodigo(int $id): void
    {
        $this->db->prepare(
            "UPDATE newsletter SET codigo_hash = NULL, codigo_expira_em = NULL WHERE id = ?"
        )->execute([$id]);
    }

    private function enviarCodigo(string $email, string $codigo): bool
    {
        try {
            return (new EmailTransacionalService())->enviar('newsletter_codigo', $email, '', [
                'codigo'    => $codigo,
                'minutos'   => (string) self::CODIGO_MINUTOS,
                'site_nome' => (string) ConfigHelper::get('site_nome', 'Loja'),
            ]);
        } catch (Throwable $e) {
            LogService::exception($e, 'error', 'app', ['onde' => 'NewsletterService::enviarCodigo']);
            return false;
        }
    }

    private function freioPorEmail(array $inscrito): ?array
    {
        $ultimo = $inscrito['codigo_enviado_em'] ? strtotime((string) $inscrito['codigo_enviado_em']) : 0;
        if ($ultimo && (time() - $ultimo) < self::REENVIO_SEGUNDOS) {
            $faltam = self::REENVIO_SEGUNDOS - (time() - $ultimo);
            return ['ok' => false, 'msg' => "Aguarde {$faltam}s para pedir outro código."];
        }

        if ((string) $inscrito['envios_data'] === date('Y-m-d')
            && (int) $inscrito['envios_dia'] >= self::MAX_ENVIOS_DIA) {
            return ['ok' => false, 'msg' => 'Muitos códigos pedidos hoje para este e-mail. Tente amanhã.'];
        }

        return null;
    }

    private function freioPorIp(string $ip): ?array
    {
        if ($ip === '') return null;

        $st = $this->db->prepare(
            "SELECT COUNT(*) FROM newsletter WHERE ip = ? AND criado_em > (NOW() - INTERVAL 1 HOUR)"
        );
        $st->execute([$ip]);

        return ((int) $st->fetchColumn() >= self::MAX_POR_IP_HORA)
            ? ['ok' => false, 'msg' => 'Muitas inscrições deste dispositivo. Tente mais tarde.']
            : null;
    }

    /** j***@dominio.com — confirma o endereço sem repeti-lo inteiro na tela. */
    private function mascarar(string $email): string
    {
        [$user, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        if ($dominio === '') return $email;

        $visivel = mb_substr($user, 0, 1);
        return $visivel . str_repeat('*', max(3, mb_strlen($user) - 1)) . '@' . $dominio;
    }
}
