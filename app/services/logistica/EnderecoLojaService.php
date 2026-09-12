<?php
/**
 * Endereço da loja no formato que uma etiqueta pede.
 *
 * Serve o botão "Usar dados da loja" da emissão manual. O caso real é a
 * etiqueta de retorno, em que a loja é o DESTINATÁRIO — o mesmo papel que o
 * `MelhorEnvioAdapter` já assume na reversa ("remetente = cliente,
 * destinatário = loja").
 *
 * ── De onde vem o dado ────────────────────────────────────────────────────
 * Da tabela `configuracoes`, editada em Configurações → Rodapé
 * (`FooterService::CHAVES_LOJA`). É a fonte que o dono chama de "configurações
 * da loja", e é a única que vale a pena ter: uma segunda cópia do endereço
 * envelhece sozinha.
 *
 * ── O problema de forma ───────────────────────────────────────────────────
 * Aquelas chaves nasceram para o RODAPÉ, que mostra uma linha só. Então:
 *
 *   - `endereco_logradouro` guarda rua E número juntos ("Avenida Juca
 *     Batista, 480"). Transportadora quer os dois separados.
 *   - `bairro` simplesmente não existe entre as chaves.
 *
 * Em vez de duplicar o endereço num segundo lugar, o que falta é completado
 * pelo CEP da própria loja no ViaCEP — a mesma fonte que o botão "Buscar" do
 * formulário já usa. O que veio da configuração sempre vence; o CEP só
 * preenche buraco.
 *
 * Nada aqui é definitivo: o resultado cai nos campos do formulário e o
 * operador confere antes de criar a etiqueta. `faltando` existe para a tela
 * poder dizer o que não conseguiu preencher, em vez de deixar o campo vazio
 * sem explicação.
 */
class EnderecoLojaService
{
    /** Chaves de `configuracoes` que alimentam o endereço. */
    private const CHAVES = [
        'nome'       => 'site_nome',
        'document'   => 'site_cnpj',
        'email'      => 'site_email',
        'telefone'   => 'site_telefone',
        'cep'        => 'endereco_cep',
        'logradouro' => 'endereco_logradouro',
        'cidade'     => 'endereco_cidade',
        'uf'         => 'endereco_uf',
    ];

    /** Sem estes a etiqueta não sai; a tela avisa quais ficaram em branco. */
    private const ESSENCIAIS = ['nome', 'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf'];

    /**
     * @return array{ok:bool, endereco:array<string,string>, faltando:array<int,string>, bairro_do_cep:bool}
     */
    public function paraEtiqueta(): array
    {
        $end = [];
        foreach (self::CHAVES as $campo => $chave) {
            $end[$campo] = trim((string) ConfigHelper::get($chave, ''));
        }
        // `configuracoes` não tem bairro — nasce vazio e o CEP resolve abaixo.
        $end['bairro'] = '';

        // "Avenida Juca Batista, 480" -> logradouro + número.
        [$end['logradouro'], $end['numero'], $end['complemento']] =
            self::separarNumero($end['logradouro']);

        $end['uf']  = mb_strtoupper($end['uf']);
        $end['cep'] = preg_replace('/\D/', '', $end['cep']) ?? '';

        // O que a configuração não tem, o CEP da loja completa. Só preenche
        // campo vazio: o que o dono cadastrou vale mais que o ViaCEP.
        $bairroDoCep = false;
        if ($end['cep'] !== '' && $this->faltaAlgumDeEndereco($end)) {
            $via = $this->viaCep($end['cep']);
            if ($via) {
                foreach (['bairro', 'logradouro', 'cidade', 'uf'] as $k) {
                    if ($end[$k] === '' && $via[$k] !== '') {
                        $end[$k] = $via[$k];
                        if ($k === 'bairro') $bairroDoCep = true;
                    }
                }
            }
        }

        $faltando = array_values(array_filter(
            self::ESSENCIAIS,
            static fn(string $k): bool => trim((string)($end[$k] ?? '')) === ''
        ));

        return [
            'ok'            => true,
            'endereco'      => $end,
            'faltando'      => $faltando,
            'bairro_do_cep' => $bairroDoCep,
        ];
    }

    /** Vale a pena bater no ViaCEP? Só se algum campo de endereço está vazio. */
    private function faltaAlgumDeEndereco(array $end): bool
    {
        foreach (['bairro', 'logradouro', 'cidade', 'uf'] as $k) {
            if (trim((string)($end[$k] ?? '')) === '') return true;
        }
        return false;
    }

    /**
     * Separa o número do fim do logradouro.
     *
     * Convenção brasileira: o número vem depois da última vírgula, ou solto no
     * fim. O que sobra depois dele é complemento ("Rua X, 480, sala 3").
     * Endereço sem número identificável volta inteiro no logradouro e o campo
     * número entra em `faltando` — chutar número de entrega é pior que deixar
     * em branco, porque ninguém confere o que já parece preenchido.
     *
     * @return array{0:string,1:string,2:string} logradouro, numero, complemento
     */
    public static function separarNumero(string $bruto): array
    {
        $bruto = trim(preg_replace('/\s+/', ' ', $bruto) ?? '');
        if ($bruto === '') return ['', '', ''];

        // "Rua X, 480, sala 3" | "Rua X, 480" | "Rua X 480"
        if (preg_match('/^(.*?)[,\s]+(\d+[A-Za-z]?)\s*(?:[,-]\s*(.*))?$/u', $bruto, $m)) {
            $logradouro  = trim(rtrim(trim($m[1]), ','));
            $numero      = trim($m[2]);
            $complemento = trim($m[3] ?? '');
            // "Rodovia BR-116 km 12": o 12 é o quilômetro, não o número da
            // porta. Separar viraria "Rodovia BR-116 km, nº 12" na etiqueta.
            $ehQuilometro = (bool) preg_match('/\bkm\.?$/iu', $logradouro);
            if ($logradouro !== '' && !$ehQuilometro) {
                return [$logradouro, $numero, $complemento];
            }
        }

        // "s/n", "sem número" — é informação, não ausência.
        if (preg_match('/^(.*?)[,\s]+(s\/?n\.?º?)$/iu', $bruto, $m) && trim($m[1]) !== '') {
            return [trim(rtrim(trim($m[1]), ',')), 'S/N', ''];
        }

        return [$bruto, '', ''];
    }

    /** ViaCEP, best-effort: timeout curto e falha silenciosa. */
    private function viaCep(string $cep): ?array
    {
        if (strlen($cep) !== 8) return null;
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
            $res = @file_get_contents("https://viacep.com.br/ws/{$cep}/json/", false, $ctx);
            $d   = $res ? json_decode($res, true) : null;
            if (!is_array($d) || !empty($d['erro'])) return null;
            return [
                'logradouro' => trim((string)($d['logradouro'] ?? '')),
                'bairro'     => trim((string)($d['bairro'] ?? '')),
                'cidade'     => trim((string)($d['localidade'] ?? '')),
                'uf'         => mb_strtoupper(trim((string)($d['uf'] ?? ''))),
            ];
        } catch (\Throwable $e) {
            return null; // o botão preenche o que sabe; o resto o operador digita
        }
    }
}
