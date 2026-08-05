<?php
/**
 * Contrato único de transportadora/intermediador logístico.
 *
 * Espelha a filosofia do PaymentGatewayInterface: cada integração
 * (Correios, Melhor Envio, Jadlog, Kangu, Frenet, Loggi...) implementa
 * este contrato e passa a ser plugável no restante do módulo sem tocar
 * em cotação, etiqueta, rastreio ou regras.
 *
 * Todos os métodos devem devolver arrays PADRONIZADOS (nunca lançar para
 * o chamador direto): erros voltam em ['ok' => false, 'erro' => '...'].
 * Assim o CotacaoService/EtiquetaService tratam qualquer transportadora
 * de forma homogênea e aplicam fallback quando uma falha.
 */
interface TransportadoraInterface
{
    /** Identificador estável (ex.: 'correios', 'melhor-envio'). */
    public function slug(): string;

    /**
     * Cota fretes.
     * @param array $params cep_origem, cep_destino, peso_g, valor,
     *                      valor_declarado, volumes[], seguro(bool), reversa(bool)
     * @return array{ok:bool, opcoes?:array<int,array{servico_codigo:string,servico_nome:string,prazo_dias:int,valor:float,tipo_postagem:string,avisos?:array,erro?:string}>, erro?:string}
     */
    public function cotar(array $params): array;

    /**
     * Gera/compra uma etiqueta.
     * IMPORTANTE: honrar a idempotency_key recebida para não duplicar
     * compra em caso de retentativa por timeout.
     * @return array{ok:bool, external_id?:string, codigo_rastreio?:string, url_pdf?:string, valor?:float, contrato?:string, erro?:string}
     */
    public function gerarEtiqueta(array $params): array;

    /**
     * Reimprime / obtém o PDF de uma ou mais etiquetas já geradas.
     * Vários IDs no mesmo request = um único PDF (manifesto/lote).
     * @param array<int,string> $externalIds
     * @return array{ok:bool, url_pdf?:string, erro?:string}
     */
    public function imprimirEtiqueta(array $externalIds, string $modo = 'private'): array;

    /**
     * Cancela uma etiqueta pela referência externa.
     * @return array{ok:bool, erro?:string}
     */
    public function cancelarEtiqueta(string $externalId): array;

    /**
     * Consulta rastreio de um código.
     * @return array{ok:bool, status_interno?:string, previsao_entrega?:?string, eventos?:array<int,array{data:string,status_transportadora:string,status_interno:string,descricao:string,local:string}>, erro?:string}
     */
    public function rastrear(string $codigo): array;

    /**
     * Gera uma logística reversa (autorização/etiqueta de retorno).
     * @return array{ok:bool, external_id?:string, codigo_rastreio?:string, url_pdf?:string, validade?:?string, erro?:string}
     */
    public function gerarReversa(array $params): array;

    /**
     * Testa a conexão/credenciais.
     * @return array{ok:bool, mensagem:string, detalhe?:array}
     */
    public function testarConexao(): array;
}
