<?php
// views/partials/footer.php
//
// Só apresentação. O conteúdo vem de FooterService, que lê `configuracoes` e
// cai em padrões de código quando não há linha no banco — então esta view
// renderiza igual em loja configurada e em banco intocado.
//
// Editar em: /admin/configuracoes/rodape
//
// Antes daqui os textos eram fixos, e o rodapé lia chaves que não existiam
// (`telefone`, `email`, `site_endereco`, `whatsapp`): o resultado era o
// fallback de exemplo — telefone de São Paulo numa loja de Porto Alegre.

$f = (new FooterService())->dados();

// Link interno recebe a base da loja; externo abre em outra aba.
$url = static function (string $u): string {
    $u = trim($u);
    if ($u === '') return '#';
    return preg_match('#^(https?:)?//#i', $u) ? $u : BASE_URL . '/' . ltrim($u, '/');
};
$externo = static fn(string $u): bool => (bool) preg_match('#^(https?:)?//#i', trim($u));

$svgSocial = [
    'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/>',
    'youtube'   => '<rect x="2.5" y="6" width="19" height="12.5" rx="3.5"/><path d="m10.2 9.6 4.6 2.65-4.6 2.65V9.6Z" fill="currentColor" stroke="none"/>',
    'facebook'  => '<path d="M14.5 8.5H17V5h-2.5A4.5 4.5 0 0 0 10 9.5V12H7.5v3.5H10V21h3.5v-5.5H16l.5-3.5h-3v-2a1.5 1.5 0 0 1 1-1.5Z"/>',
    'tiktok'    => '<path d="M14 4v9.5a3.5 3.5 0 1 1-3-3.46"/><path d="M14 4a5 5 0 0 0 5 5"/>',
];
$rotuloSocial = ['instagram' => 'Instagram', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'tiktok' => 'TikTok'];
?>

<footer class="smf_footer">

    <?php if ($f['newsletter']['ativo']): ?>
    <section class="smf_newsletter">
        <div class="smf_container">
            <div class="smf_newsletter_grid">
                <div class="smf_newsletter_text">
                    <?php if ($f['newsletter']['badge'] !== ''): ?>
                    <span class="smf_newsletter_badge">
                        <svg viewBox="0 0 24 24"><path d="M21 3 3 10.5l7.5 3L14 21l7-18Z"/></svg>
                        <?= View::e($f['newsletter']['badge']) ?>
                    </span>
                    <?php endif; ?>

                    <h2><?= View::e($f['newsletter']['titulo']) ?></h2>

                    <?php if ($f['newsletter']['texto'] !== ''): ?>
                        <p><?= View::e($f['newsletter']['texto']) ?></p>
                    <?php endif; ?>
                </div>

                <form class="smf_newsletter_form" id="smf_newsletter_form" novalidate>
                    <?= SecurityHelper::csrfField() ?>

                    <input type="email" name="email" class="smf_newsletter_input"
                           placeholder="Seu melhor e-mail" required>

                    <button type="submit" class="smf_newsletter_button">
                        <?= View::e($f['newsletter']['botao']) ?>
                    </button>

                    <span class="smf_newsletter_msg" id="smf_newsletter_msg"></span>
                </form>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($f['beneficios']): ?>
    <section class="smf_benefits">
        <div class="smf_container">
            <div class="smf_benefits_grid">
                <?php foreach ($f['beneficios'] as $b): ?>
                    <div class="smf_benefit_item">
                        <span class="smf_benefit_icon">
                            <?= FooterService::icone((string) ($b['icone'] ?? '')) ?>
                        </span>
                        <div>
                            <strong><?= View::e($b['titulo'] ?? '') ?></strong>
                            <?php if (!empty($b['texto'])): ?>
                                <small><?= View::e($b['texto']) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($b['link_texto']) && !empty($b['link_url'])): ?>
                                <a href="<?= View::e($url($b['link_url'])) ?>"><?= View::e($b['link_texto']) ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="smf_main">
        <div class="smf_container">

            <div class="smf_columns">

                <div class="smf_brand">
                    <a href="<?= BASE_URL ?>" class="smf_logo">
                        <span class="smf_logo_icon">
                            <svg viewBox="0 0 24 24"><path d="M5 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M7 12h3l2-5h3l2 5h2"/></svg>
                        </span>
                        <span><strong><?= View::e($f['nome']) ?></strong></span>
                    </a>

                    <?php if ($f['descricao'] !== ''): ?>
                        <p class="smf_desc"><?= View::e($f['descricao']) ?></p>
                    <?php endif; ?>

                    <ul class="smf_contact">
                        <?php if ($f['endereco'] !== ''): ?>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M12 21s7-4.7 7-11a7 7 0 1 0-14 0c0 6.3 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            <span><?= View::e($f['endereco']) ?></span>
                        </li>
                        <?php endif; ?>

                        <?php if ($f['telefone'] !== ''): ?>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.7 19.7 0 0 1-8.6-3.1A19.5 19.5 0 0 1 4.7 12 19.7 19.7 0 0 1 1.6 3.4 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8 8.6a16 16 0 0 0 6 6l1-1a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 2.1 3Z"/></svg>
                            <a href="tel:<?= preg_replace('/\D/', '', $f['telefone']) ?>"><?= View::e($f['telefone']) ?></a>
                        </li>
                        <?php endif; ?>

                        <?php if ($f['whats_url'] !== ''): ?>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-4-1L3 20l1.2-4.6a8.4 8.4 0 1 1 16.8-3.9Z"/></svg>
                            <a href="<?= View::e($f['whats_url']) ?>" target="_blank" rel="noopener">
                                WhatsApp: <?= View::e($f['whatsapp']) ?>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php if ($f['email'] !== ''): ?>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Z"/><path d="m4 7 8 6 8-6"/></svg>
                            <a href="mailto:<?= View::e($f['email']) ?>"><?= View::e($f['email']) ?></a>
                        </li>
                        <?php endif; ?>

                        <?php if ($f['horario'] !== ''): ?>
                        <li>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span><?= View::e($f['horario']) ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($f['social'] || $f['whats_url'] !== ''): ?>
                    <div class="smf_social">
                        <span>Siga a <?= View::e($f['nome']) ?></span>
                        <div>
                            <?php foreach ($f['social'] as $rede => $link): ?>
                                <?php if (empty($svgSocial[$rede])) continue; ?>
                                <a href="<?= View::e($link) ?>" aria-label="<?= View::e($rotuloSocial[$rede] ?? $rede) ?>"
                                   target="_blank" rel="noopener">
                                    <svg viewBox="0 0 24 24"><?= $svgSocial[$rede] ?></svg>
                                </a>
                            <?php endforeach; ?>

                            <?php if ($f['whats_url'] !== ''): ?>
                                <a href="<?= View::e($f['whats_url']) ?>" aria-label="WhatsApp" target="_blank" rel="noopener">
                                    <svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-4-1L3 20l1.2-4.6a8.4 8.4 0 1 1 16.8-3.9Z"/><path d="M9 9.5c.3 1.6 1.9 3.4 3.6 4l1.3-1c.9.3 1.6.7 1.9 1.2"/></svg>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php foreach ($f['colunas'] as $col): ?>
                    <?php if (empty($col['links'])) continue; ?>
                    <div class="smf_col">
                        <h4><?= View::e($col['titulo'] ?? '') ?></h4>
                        <ul>
                            <?php foreach ($col['links'] as $lk): ?>
                                <?php if (empty($lk['label'])) continue; ?>
                                <li>
                                    <a href="<?= View::e($url((string) ($lk['url'] ?? ''))) ?>"
                                       <?= $externo((string) ($lk['url'] ?? '')) ? 'target="_blank" rel="noopener"' : '' ?>>
                                        <?= View::e($lk['label']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>

            </div>

            <div class="smf_info_rows">
                <?php if ($f['pagamentos']): ?>
                <div class="smf_info_block">
                    <h5>Formas de pagamento</h5>
                    <div class="smf_pay">
                        <?php foreach ($f['pagamentos'] as $chave): ?>
                            <?php $svg = FooterService::pagamento((string) $chave); ?>
                            <?php if ($svg === '') continue; ?>
                            <span class="smf_pay_chip" title="<?= View::e(FooterService::pagamentos()[$chave][0] ?? $chave) ?>">
                                <?= $svg ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($f['pagamento_nota'] !== ''): ?>
                        <p><?= View::e($f['pagamento_nota']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($f['logistica']): ?>
                <div class="smf_info_block">
                    <h5>Entrega &amp; logística</h5>
                    <div class="smf_tags">
                        <?php foreach ($f['logistica'] as $t): ?>
                            <span><?= View::e($t) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($f['logistica_nota'] !== ''): ?>
                        <p><?= View::e($f['logistica_nota']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($f['selos']): ?>
                <div class="smf_info_block">
                    <h5>Segurança</h5>
                    <div class="smf_seals">
                        <?php foreach ($f['selos'] as $s): ?>
                            <span class="smf_seal">
                                <?= FooterService::icone((string) ($s['icone'] ?? '')) ?>
                                <span>
                                    <strong><?= View::e($s['titulo'] ?? '') ?></strong>
                                    <?php if (!empty($s['texto'])): ?>
                                        <small><?= View::e($s['texto']) ?></small>
                                    <?php endif; ?>
                                </span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($f['selos_nota'] !== ''): ?>
                        <p><?= View::e($f['selos_nota']) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($f['buscas']): ?>
            <div class="smf_popular">
                <h5>Buscas populares</h5>
                <div class="smf_tags">
                    <?php foreach ($f['buscas'] as $termo): ?>
                        <a href="<?= BASE_URL ?>/busca?q=<?= urlencode($termo) ?>"><?= View::e($termo) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </section>

    <section class="smf_bottom">
        <div class="smf_container">
            <div class="smf_bottom_inner">
                <p>
                    &copy; <?= date('Y') ?> <?= View::e($f['nome']) ?>. Todos os direitos reservados.
                    <?php if ($f['cnpj'] !== ''): ?>
                        <span>&bull;</span> CNPJ: <?= View::e($f['cnpj']) ?>
                    <?php endif; ?>
                    <?php if ($f['copyright_extra'] !== ''): ?>
                        <span>&bull;</span> <?= View::e($f['copyright_extra']) ?>
                    <?php endif; ?>
                </p>

                <?php if ($f['links_legais'] || $f['assinatura'] !== ''): ?>
                <nav>
                    <?php foreach ($f['links_legais'] as $lk): ?>
                        <?php if (empty($lk['label'])) continue; ?>
                        <a href="<?= View::e($url((string) ($lk['url'] ?? ''))) ?>"><?= View::e($lk['label']) ?></a>
                    <?php endforeach; ?>
                    <?php if ($f['assinatura'] !== ''): ?>
                        <span>&bull;</span> <span><?= View::e($f['assinatura']) ?></span>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </section>

</footer>

<?php include __DIR__ . '/../partials/modal-lembrar-dispositivo.php'; ?>
