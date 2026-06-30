<?php
/** @var array $itens */
$base = defined('BASE_URL') ? BASE_URL : '';
?>
<div class="em_wrapper" data-base="<?= htmlspecialchars($base) ?>">
    <div class="em_header">
        <h1>Provedores de Email</h1>
        <button type="button" class="em_btn em_btn_primary" data-em-action="novo-provedor">Novo provedor</button>
    </div>

    <table class="em_table">
        <thead><tr>
            <th>Nome</th><th>Tipo</th><th>Remetente</th><th>Limite/min</th>
            <th>Padrão</th><th>Ativo</th><th></th>
        </tr></thead>
        <tbody>
        <?php if (empty($itens)): ?>
            <tr><td colspan="7" class="em_empty">Nenhum provedor cadastrado.</td></tr>
        <?php else: foreach ($itens as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['nome']) ?></td>
                <td><span class="em_badge em_pv_<?= htmlspecialchars($p['tipo']) ?>"><?= htmlspecialchars($p['tipo']) ?></span></td>
                <td><?= htmlspecialchars($p['remetente_nome'] ?: '') ?> &lt;<?= htmlspecialchars($p['remetente_email']) ?>&gt;</td>
                <td><?= (int)$p['limite_por_minuto'] ?></td>
                <td><?= $p['padrao'] ? 'Sim' : '—' ?></td>
                <td><?= $p['ativo'] ? 'Sim' : 'Não' ?></td>
                <td>
                    <button type="button" class="em_link" data-em-action="editar-provedor"
                            data-id="<?= (int)$p['id'] ?>"
                            data-json='<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>'>Editar</button>
                    <button type="button" class="em_link" data-em-action="testar-provedor"
                            data-id="<?= (int)$p['id'] ?>">Testar</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<div id="em_modal_provedor" class="em_modal" style="display:none;">
    <div class="em_modal_box">
        <h3 id="em_modal_titulo">Novo provedor</h3>
        <form id="em_form_provedor" autocomplete="off">
            <input type="hidden" name="id" value="0">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="em_form_grid">
                <label>Nome
                    <input type="text" name="nome" required maxlength="120">
                </label>
                <label>Tipo
                    <select name="tipo" id="em_tipo">
                        <option value="ses">AWS SES</option>
                        <option value="mailgun">Mailgun</option>
                        <option value="sendgrid">SendGrid</option>
                        <option value="brevo">Brevo</option>
                        <option value="smtp">SMTP</option>
                    </select>
                </label>
                <label>Remetente (email)
                    <input type="email" name="remetente_email" required maxlength="150">
                </label>
                <label>Remetente (nome)
                    <input type="text" name="remetente_nome" maxlength="120">
                </label>
                <label>Reply-To
                    <input type="email" name="reply_to" maxlength="150">
                </label>
                <label>Domínio (mailgun/ses)
                    <input type="text" name="dominio" maxlength="150">
                </label>
                <label>Região (ses)
                    <input type="text" name="regiao" maxlength="40" placeholder="us-east-1">
                </label>
                <label>Limite/minuto
                    <input type="number" name="limite_por_minuto" value="60" min="1">
                </label>
                <label>Limite/dia
                    <input type="number" name="limite_por_dia" value="50000" min="1">
                </label>
                <label>Webhook secret
                    <input type="text" name="webhook_secret" maxlength="200">
                </label>
                <label class="em_inline">
                    <input type="checkbox" name="ativo" value="1" checked> Ativo
                </label>
                <label class="em_inline">
                    <input type="checkbox" name="padrao" value="1"> Padrão
                </label>
            </div>

            <fieldset class="em_creds">
                <legend>Credenciais</legend>
                <div class="em_creds_smtp" style="display:none;">
                    <label>Host <input type="text" name="credenciais[host]"></label>
                    <label>Porta <input type="number" name="credenciais[port]" value="587"></label>
                    <label>Usuário <input type="text" name="credenciais[username]"></label>
                    <label>Senha <input type="password" name="credenciais[password]"></label>
                    <label>Encryption
                        <select name="credenciais[encryption]">
                            <option value="tls">TLS</option><option value="ssl">SSL</option><option value="">Nenhuma</option>
                        </select>
                    </label>
                </div>
                <div class="em_creds_ses" style="display:none;">
                    <label>Access Key <input type="text" name="credenciais[access_key]"></label>
                    <label>Secret Key <input type="password" name="credenciais[secret_key]"></label>
                    <label>Region <input type="text" name="credenciais[region]" placeholder="us-east-1"></label>
                </div>
                <div class="em_creds_mailgun" style="display:none;">
                    <label>API Key <input type="password" name="credenciais[api_key]"></label>
                    <label>Domain <input type="text" name="credenciais[domain]"></label>
                    <label>Base URL <input type="text" name="credenciais[base_url]" placeholder="https://api.mailgun.net"></label>
                </div>
                <div class="em_creds_sendgrid" style="display:none;">
                    <label>API Key <input type="password" name="credenciais[api_key]"></label>
                    <label>Public Key (webhook) <textarea name="credenciais[public_key]" rows="3"></textarea></label>
                </div>
                <div class="em_creds_brevo" style="display:none;">
                    <label>API Key <input type="password" name="credenciais[api_key]"></label>
                </div>
            </fieldset>

            <div class="em_form_actions">
                <button type="button" class="em_btn" data-em-close>Cancelar</button>
                <button type="submit" class="em_btn em_btn_primary">Salvar</button>
            </div>
        </form>
    </div>
</div>
