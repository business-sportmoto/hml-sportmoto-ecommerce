<?php
// views/partials/footer.php
// Redesign: ícones sociais SVG reais, bandeiras de pagamento SVG,
// selos apenas VERDADEIROS, buscas populares com link real,
// bloco de apps removido (a loja não tem app nativo — prometer
// download que não existe corrói confiança).

$siteName   = ConfigHelper::get('site_nome', 'MotoParts');
$siteSlogan = ConfigHelper::get(
    'site_slogan',
    'A maior loja de peças e acessórios para motos do Brasil. Mais de 15 anos acelerando junto com você, com curadoria, originalidade e suporte de quem entende.'
);

$telefone = $config['telefone'] ?? ConfigHelper::get('telefone', '(11) 4002-8922');
$email    = $config['email'] ?? ConfigHelper::get('email', 'contato@motoparts.com.br');
$cnpj     = ConfigHelper::get('site_cnpj', '00.000.000/0001-00');
$endereco = ConfigHelper::get('site_endereco', 'Av. das Motos, 1500 — Vila Olímpia, São Paulo / SP — CEP 04551-000');

// WhatsApp: usa config própria; se vier sem DDI, prefixa 55
$whats       = ConfigHelper::get('whatsapp', $telefone);
$whatsDigits = preg_replace('/\D/', '', $whats);
if (strlen($whatsDigits) <= 11) { $whatsDigits = '55' . $whatsDigits; }

// Redes sociais — preencher as URLs no admin (ConfigHelper)
$social = [
    'instagram' => ConfigHelper::get('social_instagram', '#'),
    'youtube'   => ConfigHelper::get('social_youtube',   '#'),
    'facebook'  => ConfigHelper::get('social_facebook',  '#'),
    'x'         => ConfigHelper::get('social_x',         '#'),
    'whatsapp'  => 'https://wa.me/' . $whatsDigits,
];

// Buscas populares — ajuste a rota se a sua busca não for /busca?q=
$buscaBase = BASE_URL . '/busca?q=';
$buscasPopulares = [
    'capacete fechado', 'capacete articulado', 'jaqueta de couro',
    'luva motociclista', 'pneu pirelli', 'pneu michelin',
    'escapamento esportivo', 'óleo motul', 'bateria moto',
    'bagageiro givi', 'bauleto 50 litros', 'intercomunicador',
];

$paginasMenu = [];
if (class_exists('PageController')) {
    $paginasMenu = array_filter(
        PageController::getAllPages(),
        fn($p) => !empty($p['no_menu'])
    );
}
?>

<footer class="smf_footer">

    <section class="smf_newsletter">
        <div class="smf_container">
            <div class="smf_newsletter_grid">
                <div class="smf_newsletter_text">
                    <span class="smf_newsletter_badge">
                        <svg viewBox="0 0 24 24"><path d="M21 3 3 10.5l7.5 3L14 21l7-18Z"/></svg>
                        Assine e ganhe R$ 10 off
                    </span>

                    <h2>
                        Acelere com a gente.
                        <strong>Cupom exclusivo</strong> na<br>
                        primeira compra.
                    </h2>

                    <p>
                        Receba lançamentos, ofertas relâmpago e conteúdos para motociclistas
                        direto no seu e-mail.
                    </p>
                </div>

                <form class="smf_newsletter_form" id="smf_newsletter_form" novalidate>
                    <?= SecurityHelper::csrfField() ?>

                    <input
                        type="email"
                        name="email"
                        class="smf_newsletter_input"
                        placeholder="Seu melhor e-mail"
                        required
                    >

                    <button type="submit" class="smf_newsletter_button">
                        Quero meu cupom
                    </button>

                    <span class="smf_newsletter_msg" id="smf_newsletter_msg"></span>
                </form>
            </div>
        </div>
    </section>

    <section class="smf_benefits">
        <div class="smf_container">
            <div class="smf_benefits_grid">

                <div class="smf_benefit_item">
                    <span class="smf_benefit_icon">
                        <svg viewBox="0 0 24 24"><path d="M3 7h11v10H3V7Zm11 4h4l3 3v3h-7v-6Z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/></svg>
                    </span>
                    <div>
                        <strong>Frete grátis</strong>
                        <small>Acima de R$ 299</small>
                        <a href="<?= BASE_URL ?>/prazos-de-entrega">*Consulte regras</a>
                    </div>
                </div>

                <div class="smf_benefit_item">
                    <span class="smf_benefit_icon">
                        <svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Z"/><path d="m8.5 12 2.2 2.2L15.8 9"/></svg>
                    </span>
                    <div>
                        <strong>Compra segura</strong>
                        <small>Ambiente SSL</small>
                    </div>
                </div>

                <div class="smf_benefit_item">
                    <span class="smf_benefit_icon">
                        <svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/></svg>
                    </span>
                    <div>
                        <strong>Até 12x sem juros</strong>
                        <small>Cartão de crédito</small>
                    </div>
                </div>

                <div class="smf_benefit_item">
                    <span class="smf_benefit_icon">
                        <svg viewBox="0 0 24 24"><path d="M4 13a8 8 0 0 1 16 0"/><path d="M4 13v4a2 2 0 0 0 2 2h1v-8H6a2 2 0 0 0-2 2Zm16 0v4a2 2 0 0 1-2 2h-1v-8h1a2 2 0 0 1 2 2Z"/></svg>
                    </span>
                    <div>
                        <strong>Suporte 7 dias</strong>
                        <small>Atendimento humano</small>
                    </div>
                </div>

                <div class="smf_benefit_item">
                    <span class="smf_benefit_icon">
                        <svg viewBox="0 0 24 24"><path d="M12 15a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/><path d="m8.5 14.5-1 7 4.5-2.5 4.5 2.5-1-7"/></svg>
                    </span>
                    <div>
                        <strong>Garantia real</strong>
                        <small>3 meses em produtos</small>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <section class="smf_main">
        <div class="smf_container">

            <div class="smf_columns">

                <div class="smf_brand">
                    <a href="<?= BASE_URL ?>" class="smf_logo">
                        <span class="smf_logo_icon">
                            <svg viewBox="0 0 24 24"><path d="M5 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm14 0a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M7 12h3l2-5h3l2 5h2"/></svg>
                        </span>
                        <span>
                            <strong><?= View::e($siteName) ?></strong>
                            <small>Premium Store</small>
                        </span>
                    </a>

                    <p class="smf_desc">
                        <?= View::e($siteSlogan) ?>
                    </p>

                    <ul class="smf_contact">
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M12 21s7-4.7 7-11a7 7 0 1 0-14 0c0 6.3 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                            <span><?= View::e($endereco) ?></span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.7 19.7 0 0 1-8.6-3.1A19.5 19.5 0 0 1 4.7 12 19.7 19.7 0 0 1 1.6 3.4 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8 8.6a16 16 0 0 0 6 6l1-1a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 2.1 3Z"/></svg>
                            <a href="tel:<?= preg_replace('/\D/', '', $telefone) ?>"><?= View::e($telefone) ?> — Central de vendas</a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-4-1L3 20l1.2-4.6a8.4 8.4 0 1 1 16.8-3.9Z"/></svg>
                            <a href="https://wa.me/<?= $whatsDigits ?>" target="_blank" rel="noopener">WhatsApp: <?= View::e($whats) ?></a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Z"/><path d="m4 7 8 6 8-6"/></svg>
                            <a href="mailto:<?= View::e($email) ?>"><?= View::e($email) ?></a>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span>Seg a Sáb: 8h às 20h · Dom: 10h às 16h</span>
                        </li>
                    </ul>

                    <div class="smf_social">
                        <span>Siga a <?= View::e($siteName) ?></span>
                        <div>
                            <a href="<?= View::e($social['instagram']) ?>" aria-label="Instagram" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r="1.1" fill="currentColor" stroke="none"/></svg>
                            </a>
                            <a href="<?= View::e($social['youtube']) ?>" aria-label="YouTube" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24"><rect x="2.5" y="6" width="19" height="12.5" rx="3.5"/><path d="m10.2 9.6 4.6 2.65-4.6 2.65V9.6Z" fill="currentColor" stroke="none"/></svg>
                            </a>
                            <a href="<?= View::e($social['facebook']) ?>" aria-label="Facebook" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24"><path d="M14.5 8.5H17V5h-2.5A4.5 4.5 0 0 0 10 9.5V12H7.5v3.5H10V21h3.5v-5.5H16l.5-3.5h-3v-2a1.5 1.5 0 0 1 1-1.5Z"/></svg>
                            </a>
                            <a href="<?= View::e($social['x']) ?>" aria-label="X (Twitter)" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24"><path d="M4 4l7.2 9.3L4.4 20h2.4l5.5-5.4L16.8 20H20l-7.5-9.7L18.9 4h-2.4l-4.9 4.9L7.2 4H4Z"/></svg>
                            </a>
                            <a href="<?= View::e($social['whatsapp']) ?>" aria-label="WhatsApp" target="_blank" rel="noopener">
                                <svg viewBox="0 0 24 24"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-4-1L3 20l1.2-4.6a8.4 8.4 0 1 1 16.8-3.9Z"/><path d="M9 9.5c.3 1.6 1.9 3.4 3.6 4l1.3-1c.9.3 1.6.7 1.9 1.2"/></svg>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="smf_col">
                    <h4>Institucional</h4>
                    <ul>
                        <?php if (!empty($paginasMenu)): ?>
                            <?php foreach ($paginasMenu as $pg): ?>
                                <li>
                                    <a href="<?= BASE_URL ?>/<?= View::e($pg['slug']) ?>">
                                        <?= View::e($pg['menu_label'] ?? $pg['titulo']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li><a href="<?= BASE_URL ?>/sobre-a-motoparts">Sobre a MotoParts</a></li>
                            <li><a href="<?= BASE_URL ?>/historia">Nossa história</a></li>
                            <li><a href="<?= BASE_URL ?>/trabalhe-conosco">Trabalhe conosco</a></li>
                            <li><a href="<?= BASE_URL ?>/imprensa">Imprensa</a></li>
                            <li><a href="<?= BASE_URL ?>/sustentabilidade">Sustentabilidade</a></li>
                            <li><a href="<?= BASE_URL ?>/afiliados">Programa de afiliados</a></li>
                            <li><a href="<?= BASE_URL ?>/blog">Blog & Notícias</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="smf_col">
                    <h4>Atendimento</h4>
                    <ul>
                        <li><a href="<?= BASE_URL ?>/central-de-ajuda">Central de ajuda</a></li>
                        <li><a href="<?= BASE_URL ?>/como-comprar">Como comprar</a></li>
                        <li><a href="<?= BASE_URL ?>/prazos-de-entrega">Prazos de entrega</a></li>
                        <li><a href="<?= BASE_URL ?>/trocas-e-devolucoes">Trocas e devoluções</a></li>
                        <li><a href="<?= BASE_URL ?>/rastrear-pedido">Rastrear pedido</a></li>
                        <li><a href="<?= BASE_URL ?>/politica-de-privacidade">Política de privacidade</a></li>
                        <li><a href="<?= BASE_URL ?>/termos-de-uso">Termos de uso</a></li>
                    </ul>
                </div>

                <div class="smf_col">
                    <h4>Categorias</h4>
                    <ul>
                        <li><a href="<?= BASE_URL ?>/categoria/capacetes">Capacetes</a></li>
                        <li><a href="<?= BASE_URL ?>/categoria/vestuario-protecao">Vestuário & Proteção</a></li>
                        <li><a href="<?= BASE_URL ?>/categoria/pneus-rodas">Pneus & Rodas</a></li>
                        <li><a href="<?= BASE_URL ?>/categoria/mecanica-motor">Mecânica & Motor</a></li>
                        <li><a href="<?= BASE_URL ?>/categoria/performance-tuning">Performance & Tuning</a></li>
                        <li><a href="<?= BASE_URL ?>/categoria/acessorios">Acessórios</a></li>
                        <li><a href="<?= BASE_URL ?>/categoria/eletronicos">Eletrônicos</a></li>
                    </ul>
                </div>

                <div class="smf_col">
                    <h4>Minha conta</h4>
                    <ul>
                        <li><a href="<?= BASE_URL ?>/minha-conta/pedidos">Meus pedidos</a></li>
                        <li><a href="<?= BASE_URL ?>/minha-conta/favoritos">Lista de desejos</a></li>
                        <li><a href="<?= BASE_URL ?>/minha-conta/carteira">Carteira & Cashback</a></li>
                        <li><a href="<?= BASE_URL ?>/minha-conta/fidelidade">Programa fidelidade</a></li>
                        <li><a href="<?= BASE_URL ?>/indique-e-ganhe">Indique e ganhe</a></li>
                        <li><a href="<?= BASE_URL ?>/minha-conta/notificacoes">Notificações</a></li>
                        <li><a href="<?= BASE_URL ?>/minha-conta/enderecos">Endereços salvos</a></li>
                    </ul>
                </div>

            </div>

            <div class="smf_info_rows">
                <div class="smf_info_block">
                    <h5>Formas de pagamento</h5>
                    <div class="smf_pay">
                        <span class="smf_pay_chip" title="Visa">
                            <svg viewBox="0 0 48 30"><text x="24" y="20" text-anchor="middle" font-size="11" font-weight="800" font-style="italic" fill="#1434CB" letter-spacing=".5">VISA</text></svg>
                        </span>
                        <span class="smf_pay_chip" title="Mastercard">
                            <svg viewBox="0 0 48 30"><circle cx="20" cy="15" r="8.5" fill="#EB001B"/><circle cx="28" cy="15" r="8.5" fill="#F79E1B"/><path d="M24 8.6a8.5 8.5 0 0 1 0 12.8 8.5 8.5 0 0 1 0-12.8Z" fill="#FF5F00"/></svg>
                        </span>
                        <span class="smf_pay_chip" title="American Express">
                            <svg viewBox="0 0 48 30"><rect width="48" height="30" rx="5" fill="#2E77BC"/><text x="24" y="19" text-anchor="middle" font-size="8.5" font-weight="800" fill="#fff" letter-spacing=".4">AMEX</text></svg>
                        </span>
                        <span class="smf_pay_chip" title="Elo">
                            <svg viewBox="0 0 48 30"><text x="24" y="20" text-anchor="middle" font-size="12" font-weight="900" fill="#0f172a">elo</text><circle cx="35.5" cy="11" r="2" fill="#FFCB05"/></svg>
                        </span>
                        <span class="smf_pay_chip" title="Hipercard">
                            <svg viewBox="0 0 48 30"><rect width="48" height="30" rx="5" fill="#B3131B"/><text x="24" y="19" text-anchor="middle" font-size="8" font-weight="800" font-style="italic" fill="#fff">Hipercard</text></svg>
                        </span>
                        <span class="smf_pay_chip" title="Diners Club">
                            <svg viewBox="0 0 48 30"><circle cx="24" cy="15" r="9" fill="#0079BE"/><rect x="21.4" y="8.4" width="5.2" height="13.2" rx="2.6" fill="#fff"/></svg>
                        </span>
                        <span class="smf_pay_chip" title="Pix">
                            <svg viewBox="0 0 48 30"><g transform="translate(24 15)"><rect x="-6.4" y="-6.4" width="12.8" height="12.8" rx="3.4" transform="rotate(45)" fill="none" stroke="#32BCAD" stroke-width="2.6"/><rect x="-2.5" y="-2.5" width="5" height="5" rx="1.4" transform="rotate(45)" fill="#32BCAD"/></g></svg>
                        </span>
                        <span class="smf_pay_chip" title="Boleto bancário">
                            <svg viewBox="0 0 48 30"><g fill="#0f172a"><rect x="10" y="8" width="2.4" height="14"/><rect x="14.5" y="8" width="1.2" height="14"/><rect x="17.8" y="8" width="3" height="14"/><rect x="22.8" y="8" width="1.2" height="14"/><rect x="26" y="8" width="2.2" height="14"/><rect x="30.4" y="8" width="1.2" height="14"/><rect x="33.6" y="8" width="3" height="14"/><rect x="38.6" y="8" width="1.4" height="14"/></g></svg>
                        </span>
                        <span class="smf_pay_chip" title="PicPay">
                            <svg viewBox="0 0 48 30"><rect width="48" height="30" rx="5" fill="#21C25E"/><text x="24" y="19.5" text-anchor="middle" font-size="9" font-weight="800" fill="#fff">PicPay</text></svg>
                        </span>
                    </div>
                    <p>Parcele em até 12x sem juros no cartão · 5% OFF no Pix</p>
                </div>

                <div class="smf_info_block">
                    <h5>Entrega & logística</h5>
                    <div class="smf_tags">
                        <span>Correios</span><span>Sedex</span><span>Jadlog</span><span>Loggi</span>
                        <span>Total Express</span><span>Retira na loja</span>
                    </div>
                    <p>Entregamos em todo o Brasil · Despacho em até 24h</p>
                </div>

                <div class="smf_info_block">
                    <h5>Segurança</h5>
                    <div class="smf_seals">
                        <span class="smf_seal">
                            <svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                            <span><strong>Conexão SSL</strong><small>Criptografia 256-bit</small></span>
                        </span>
                        <span class="smf_seal">
                            <svg viewBox="0 0 24 24"><path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Z"/><path d="m8.5 12 2.2 2.2L15.8 9"/></svg>
                            <span><strong>LGPD</strong><small>Dados protegidos por lei</small></span>
                        </span>
                        <span class="smf_seal">
                            <svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 15h4"/></svg>
                            <span><strong>Pagamento PCI DSS</strong><small>Cartão nunca fica na loja</small></span>
                        </span>
                    </div>
                    <p>Checkout criptografado de ponta a ponta</p>
                </div>
            </div>

            <div class="smf_popular">
                <h5>Buscas populares</h5>
                <div class="smf_tags">
                    <?php foreach ($buscasPopulares as $termo): ?>
                        <a href="<?= $buscaBase . urlencode($termo) ?>"><?= View::e($termo) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </section>

    <section class="smf_bottom">
        <div class="smf_container">
            <div class="smf_bottom_inner">
                <p>
                    © <?= date('Y') ?> <?= View::e($siteName) ?>. Todos os direitos reservados.
                    <span>•</span>
                    CNPJ: <?= View::e($cnpj) ?>
                    <span>•</span>
                    Preços e condições exclusivos para o site.
                </p>

                <nav>
                    <a href="<?= BASE_URL ?>/politica-de-privacidade">Privacidade</a>
                    <a href="<?= BASE_URL ?>/cookies">Gerenciar cookies</a>
                    <a href="<?= BASE_URL ?>/termos-de-uso">Termos</a>
                    <a href="<?= BASE_URL ?>/mapa-do-site">Mapa do site</a>
                    <span>•</span>
                    <span>Feito por quem anda de moto</span>
                </nav>
            </div>
        </div>
    </section>

</footer>

<?php include __DIR__ . '/../partials/modal-lembrar-dispositivo.php'; ?>