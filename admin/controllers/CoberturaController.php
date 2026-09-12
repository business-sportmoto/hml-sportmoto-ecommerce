<?php
/**
 * CoberturaController — áreas de entrega, operação diária e feriados.
 *
 * Serve a tela de configuração de envio das transportadoras que cotam
 * LOCALMENTE (hoje a LogManager): quem tem o preço na nossa mão é quem pode
 * ter área com custo próprio. Transportadora que cota por API traz o preço
 * dela e não aparece aqui.
 *
 * Permissão em cascata como o resto da logística; CSRF em todo POST.
 */
class CoberturaController extends Controller
{
    /** Adapters que cotam sem chamar a transportadora. */
    private const ADAPTERS_LOCAIS = ['LogManagerAdapter'];

    private AreaEntregaService $areas;

    public function __construct()
    {
        AuthHelper::requirePermissaoOuNivel('logistica', 'super', 'gerente', 'estoque');
        $this->areas = new AreaEntregaService();
    }

    /* ============================ TELA ============================ */

    /** GET /admin/logistica/cobertura */
    public function index(): void
    {
        $transportadoras = $this->locais();
        $id = (int) ($_GET['transportadora'] ?? 0);
        if ($id <= 0 || !isset($transportadoras[$id])) {
            $id = (int) (array_key_first($transportadoras) ?? 0);
        }

        $this->render('logistica/cobertura', [
            'titulo'          => 'Configuração de envios',
            'transportadoras' => array_values($transportadoras),
            'transportadora'  => $transportadoras[$id] ?? null,
            'dados'           => $id > 0 ? $this->pacote($id) : null,
            'cep_loja'        => preg_replace('/\D/', '', (string) ConfigHelper::get('endereco_cep', '')) ?: '',
        ], 'admin');
    }

    /** GET /admin/logistica/cobertura/dados?transportadora= */
    public function dados(): void
    {
        $id = $this->transportadoraValida((int) ($_GET['transportadora'] ?? 0));
        if (!$id) { $this->json(['ok' => false, 'erro' => 'Transportadora inválida.']); return; }
        $this->json(['ok' => true] + $this->pacote($id));
    }

    /* ========================== ESCRITA =========================== */

    /** POST /admin/logistica/cobertura/area */
    public function salvarArea(): void
    {
        $this->verifyCsrf();
        $id = $this->transportadoraValida((int) ($_POST['transportadora_id'] ?? 0));
        if (!$id) { $this->json(['ok' => false, 'erro' => 'Transportadora inválida.']); return; }

        $this->json($this->areas->salvarArea([
            'id'                => (int) ($_POST['id'] ?? 0),
            'transportadora_id' => $id,
            'nome'              => $_POST['nome'] ?? '',
            'banda'             => $_POST['banda'] ?? 'proxima',
            'faixas_cep'        => $_POST['faixas_cep'] ?? '',
            'valor_base'        => $_POST['valor_base'] ?? 0,
            'prazo_dias'        => $_POST['prazo_dias'] ?? null,
            'cutoff_hora'       => $_POST['cutoff_hora'] ?? null,
            'max_envios_dia'    => $_POST['max_envios_dia'] ?? null,
            'ativa'             => $_POST['ativa'] ?? 1,
            'ordem'             => $_POST['ordem'] ?? 100,
            'mapa_lat'          => $_POST['mapa_lat'] ?? null,
            'mapa_lng'          => $_POST['mapa_lng'] ?? null,
            'mapa_raio_km'      => $_POST['mapa_raio_km'] ?? null,
        ], AuthHelper::usuarioId()));
    }

    /** POST /admin/logistica/cobertura/area/excluir */
    public function excluirArea(): void
    {
        $this->verifyCsrf();
        $areaId = (int) ($_POST['id'] ?? 0);
        if ($areaId <= 0) { $this->json(['ok' => false, 'erro' => 'Área inválida.']); return; }
        $this->json($this->areas->excluirArea($areaId, AuthHelper::usuarioId()));
    }

    /** POST /admin/logistica/cobertura/area/alternar */
    public function alternarArea(): void
    {
        $this->verifyCsrf();
        $areaId = (int) ($_POST['id'] ?? 0);
        if ($areaId <= 0) { $this->json(['ok' => false, 'erro' => 'Área inválida.']); return; }
        $this->json($this->areas->alternarArea($areaId, !empty($_POST['ativa']), AuthHelper::usuarioId()));
    }

    /** POST /admin/logistica/cobertura/operacao */
    public function salvarOperacao(): void
    {
        $this->verifyCsrf();
        $id = $this->transportadoraValida((int) ($_POST['transportadora_id'] ?? 0));
        if (!$id) { $this->json(['ok' => false, 'erro' => 'Transportadora inválida.']); return; }

        $dias = is_array($_POST['dias'] ?? null) ? $_POST['dias'] : [];
        if (!$dias) { $this->json(['ok' => false, 'erro' => 'Nada para salvar.']); return; }

        $res = $this->areas->salvarOperacao($id, $dias, AuthHelper::usuarioId());
        if (!empty($res['ok']) && is_array($_POST['limites'] ?? null)) {
            $this->areas->salvarLimites($id, $_POST['limites'], AuthHelper::usuarioId());
        }
        $this->json($res);
    }

    /** POST /admin/logistica/cobertura/feriado */
    public function salvarFeriado(): void
    {
        $this->verifyCsrf();
        $id = $this->transportadoraValida((int) ($_POST['transportadora_id'] ?? 0));
        if (!$id) { $this->json(['ok' => false, 'erro' => 'Transportadora inválida.']); return; }
        $this->json($this->areas->salvarFeriado(
            $id, (string) ($_POST['data'] ?? ''), !empty($_POST['opera']), AuthHelper::usuarioId()
        ));
    }

    /**
     * GET /admin/logistica/cobertura/testar-cep?transportadora=&cep=
     *
     * Diz qual área responderia por um CEP. Existe porque faixa sobreposta é
     * normal e a ordem decide — sem um teste, o operador só descobre qual
     * venceu quando um cliente reclama do preço.
     */
    public function testarCep(): void
    {
        $id = $this->transportadoraValida((int) ($_GET['transportadora'] ?? 0));
        if (!$id) { $this->json(['ok' => false, 'erro' => 'Transportadora inválida.']); return; }

        $cep = preg_replace('/\D/', '', (string) ($_GET['cep'] ?? '')) ?? '';
        if (strlen($cep) !== 8) { $this->json(['ok' => false, 'erro' => 'Informe um CEP com 8 dígitos.']); return; }

        $area = $this->areas->resolver($id, $cep);
        $this->json(['ok' => true, 'cep' => $cep, 'area' => $area ? [
            'id' => (int) $area['id'], 'nome' => $area['nome'], 'banda' => $area['banda'],
            'valor_base' => (float) $area['valor_base'],
            'prazo_dias' => $area['prazo_dias'] !== null ? (int) $area['prazo_dias'] : null,
        ] : null]);
    }

    /* ========================== Helpers ============================ */

    /** Tudo que a tela precisa numa carga só. */
    private function pacote(int $id): array
    {
        return [
            'transportadora_id' => $id,
            'areas'    => $this->areas->areas($id),
            'operacao' => array_values($this->areas->operacao($id)),
            'feriados' => $this->areas->feriados($id, 4),
            'limites'  => $this->areas->limites($id),
            'resumo'   => $this->areas->resumo($id),
            'bandas'   => AreaEntregaService::BANDAS,
        ];
    }

    /** @return array<int,array> transportadoras de cotação local, por id. */
    private function locais(): array
    {
        $in = implode(',', array_fill(0, count(self::ADAPTERS_LOCAIS), '?'));
        try {
            $st = Database::getInstance()->getConnection()->prepare(
                "SELECT id, nome, slug, adapter, status FROM log_transportadoras
                  WHERE adapter IN ($in) ORDER BY prioridade ASC, nome ASC"
            );
            $st->execute(self::ADAPTERS_LOCAIS);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $t) $out[(int) $t['id']] = $t;
            return $out;
        } catch (\Throwable $e) {
            LogService::error('Falha ao listar transportadoras locais', ['erro' => $e->getMessage()]);
            return [];
        }
    }

    /** O id existe E é de cotação local? Devolve 0 quando não. */
    private function transportadoraValida(int $id): int
    {
        return isset($this->locais()[$id]) ? $id : 0;
    }
}
