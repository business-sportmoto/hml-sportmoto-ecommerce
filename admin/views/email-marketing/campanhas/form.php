<?php
/** @var array|null $item */
/** @var array $provedores */
/** @var array $templates */
/** @var array $listas */
/** @var array $segmentos */
$base = defined('BASE_URL') ? BASE_URL : '';
$c = $item ?: [
    'id'=>0,'nome'=>'','provedor_id'=>0,'template_id'=>0,'lista_id'=>0,'segmento_id'=>0,
    'assunto_override'=>'','preheader_override'=>'',
    'remetente_email'=>'','remetente_nome'=>'','reply_to'=>'',
    'agendada_para'=>'','batch_size'=>200,'intervalo_segundos'=>1,
];
$agend = '';
if (!empty($c['agendada_para']) && $c['agendada_para'] !== '0000-00-00 00:00:00') {
    $agend = date('Y-m-d\TH:i', strtotime($c['agendada_para']));
}
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1><?= $c['id'] ? 'Editar' : 'Nova' ?> campanha</h1>
        <a href="<?= $base ?>/admin/email-marketing/campanhas" class="em_btn">Voltar</a>
    </div>

    <form id="em_form_campanha" class="em_form">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <label>Nome <input type="text" name="nome" required maxlength="160" value="<?= htmlspecialchars($c['nome']) ?>"></label>

        <div class="em_form_grid">
            <label>Provedor
                <select name="provedor_id" required>
                    <option value="">— escolher —</option>
                    <?php foreach ($provedores as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= (int)$c['provedor_id']===(int)$p['id']?'selected':'' ?>>
                            <?= htmlspecialchars($p['nome']) ?> (<?= htmlspecialchars($p['tipo']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Template
                <select name="template_id" required>
                    <option value="">— escolher —</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>" <?= (int)$c['template_id']===(int)$t['id']?'selected':'' ?>>
                            <?= htmlspecialchars($t['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="em_form_grid">
            <label>Lista (OU)
                <select name="lista_id">
                    <option value="">— nenhuma —</option>
                    <?php foreach ($listas as $l): ?>
                        <option value="<?= (int)$l['id'] ?>" <?= (int)$c['lista_id']===(int)$l['id']?'selected':'' ?>>
                            <?= htmlspecialchars($l['nome']) ?> (<?= (int)$l['total_contatos'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Segmento (OU)
                <select name="segmento_id">
                    <option value="">— nenhum —</option>
                    <?php foreach ($segmentos as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= (int)$c['segmento_id']===(int)$s['id']?'selected':'' ?>>
                            <?= htmlspecialchars($s['nome']) ?> (~<?= (int)$s['total_estimado'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div class="em_form_grid">
            <label>Assunto (override)
                <input type="text" name="assunto_override" maxlength="250" value="<?= htmlspecialchars($c['assunto_override'] ?? '') ?>">
            </label>
            <label>Preheader (override)
                <input type="text" name="preheader_override" maxlength="250" value="<?= htmlspecialchars($c['preheader_override'] ?? '') ?>">
            </label>
        </div>

        <div class="em_form_grid">
            <label>Remetente — email
                <input type="email" name="remetente_email" maxlength="150" value="<?= htmlspecialchars($c['remetente_email'] ?? '') ?>">
            </label>
            <label>Remetente — nome
                <input type="text" name="remetente_nome" maxlength="120" value="<?= htmlspecialchars($c['remetente_nome'] ?? '') ?>">
            </label>
            <label>Reply-To
                <input type="email" name="reply_to" maxlength="150" value="<?= htmlspecialchars($c['reply_to'] ?? '') ?>">
            </label>
        </div>

        <div class="em_form_grid">
            <label>Agendamento
                <input type="datetime-local" name="agendada_para" value="<?= htmlspecialchars($agend) ?>">
                <small>Deixe em branco para enviar logo após enfileirar.</small>
            </label>
            <label>Batch size (por lote)
                <input type="number" name="batch_size" value="<?= (int)$c['batch_size'] ?>" min="1" max="2000">
            </label>
            <label>Intervalo entre lotes (s)
                <input type="number" name="intervalo_segundos" value="<?= (int)$c['intervalo_segundos'] ?>" min="0" max="120">
            </label>
        </div>

        <div class="em_form_actions">
            <?php if ($c['id']): ?>
                <input type="email" id="em_email_teste" placeholder="Email para teste...">
                <button type="button" class="em_btn" data-em-action="testar-campanha" data-id="<?= (int)$c['id'] ?>">Enviar teste</button>
            <?php endif; ?>
            <button type="submit" class="em_btn em_btn_primary">Salvar</button>
        </div>
    </form>
</div>
