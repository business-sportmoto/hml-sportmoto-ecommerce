<?php
// app/presenters/PresenterContext.php
// Tudo que um presenter precisa saber sobre "quem está pedindo".
//
// Existe para impor a regra dura da camada de apresentação da API:
// PRESENTER NUNCA TOCA Session, NUNCA FAZ QUERY POR ITEM.
//
// Quem lê a sessão é o controller, uma vez, ao montar este objeto. Daí para
// baixo tudo é explícito — o que também torna os presenters testáveis sem
// levantar sessão nenhuma.

final class PresenterContext
{
    public function __construct(
        public readonly ?int   $clienteId,
        public readonly string $sessaoKey,
        public readonly ?array $veiculoAtivo,
        public readonly string $baseUrl,
        public readonly string $uploadUrl,
        public readonly string $assetUrl,
        public readonly ?int   $dispositivoId,
        public readonly string $plataforma = 'app',
    ) {}

    /**
     * Monta o contexto a partir do dispositivo autenticado.
     * Chamado por AppApiController::contexto() — o único ponto do fluxo da API
     * autorizado a ler $_SESSION.
     */
    public static function deDispositivo(?array $dispositivo, ?int $clienteId = null): self
    {
        $veiculo = null;
        if (session_status() === PHP_SESSION_ACTIVE || isset($_SESSION)) {
            $veiculo = $_SESSION['meu_veiculo'] ?? null;
        }

        return new self(
            clienteId:     $clienteId,
            sessaoKey:     session_id() ?: '',
            veiculoAtivo:  is_array($veiculo) ? $veiculo : null,
            baseUrl:       rtrim(BASE_URL, '/'),
            uploadUrl:     rtrim(UPLOAD_URL, '/'),
            assetUrl:      rtrim(ASSET_URL, '/'),
            dispositivoId: $dispositivo ? (int)$dispositivo['id'] : null,
            plataforma:    $dispositivo['plataforma'] ?? 'app',
        );
    }

    /** Contexto anônimo — endpoints abertos e testes. */
    public static function anonimo(): self
    {
        return new self(
            clienteId:     null,
            sessaoKey:     '',
            veiculoAtivo:  null,
            baseUrl:       rtrim(BASE_URL, '/'),
            uploadUrl:     rtrim(UPLOAD_URL, '/'),
            assetUrl:      rtrim(ASSET_URL, '/'),
            dispositivoId: null,
        );
    }

    public function logado(): bool
    {
        return $this->clienteId !== null && $this->clienteId > 0;
    }

    public function temVeiculo(): bool
    {
        return !empty($this->veiculoAtivo['montadora_id']);
    }

    /**
     * Absolutiza uma URL de mídia. O app não tem "URL relativa ao site" —
     * toda imagem precisa sair daqui pronta para o <Image>.
     */
    public function url(?string $caminho, string $base = 'upload'): ?string
    {
        if ($caminho === null || trim($caminho) === '') {
            return null;
        }
        $caminho = trim($caminho);

        if (preg_match('#^https?://#i', $caminho)) {
            return $caminho;
        }

        $raiz = match ($base) {
            'asset' => $this->assetUrl,
            'base'  => $this->baseUrl,
            default => $this->uploadUrl,
        };

        return $raiz . '/' . ltrim($caminho, '/');
    }
}
