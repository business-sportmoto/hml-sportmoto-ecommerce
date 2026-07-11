<?php
// app/controllers/HomeController.php

class HomeController extends Controller {

    public function index(): void {
        $bannerModel      = new Banner();
        $categoryModel    = new Category();
        $productModel     = new Product();
        // $testimonialModel = new Testimonial();

        SeoHelper::setTitle(ConfigHelper::get('site_nome', 'Minha Loja'));
        SeoHelper::setDescription(ConfigHelper::get('seo_description', ''));
        SeoHelper::setCanonical(BASE_URL);

        $produtosCompativeis = [];
        $veiculoAtivo = $_SESSION['meu_veiculo'] ?? null;

        if ($veiculoAtivo && !empty($products)) {
            $svc = new VeiculoService();
            $ids = array_column($products, 'id');
            $produtosCompativeis = $svc->getProdutosCompativeisLote($ids);
        }

        // $marcasMenu = CacheHelper::delete('categoryModelHome');
        $categoryModelHome = CacheHelper::get('categoryModelHome');
        if (!$categoryModelHome) {
            $categoryModelHome = $categoryModel->getActive(0, true, true);
            CacheHelper::set('categoryModelHome', $categoryModelHome, 3600);
            // CacheHelper::set('menu_marcas', $marcasMenu, 3600);
        }

        // HomeController@index
        $clienteId  = Session::isClienteLogado() ? (int)Session::get('cliente_id') : null;
        $sessionKey = PersonalizationService::getSessionKey();
        $svc        = new PersonalizationService($clienteId, $sessionKey);
        $sections   = $svc->buildHomeSectionsPrepare();
        
        $streamSvc  = new StreamService(
            getenv('CF_ACCOUNT_ID'),
            getenv('CF_STREAM_TOKEN'),
            getenv('CF_STREAM_CUSTOMER_CODE') ?? ''
        );

        $this->render('home/index', [            
            'categorias'         => $categoryModelHome,
            
            'produtos_destaque'  => $sections['sectionDestaque'] ?? [],
            'produtos_promocao'  => $sections['sectionPromocoes'] ?? [], //$productModel->getOnSale(15),
            'mais_vendidos'      => $sections['sectionMaisVendidos'] ?? [],//$productModel->getBestSellers(15),
            'lancamentos'        => $sections['sectionNovidades'] ?? [], //$productModel->getRecent(15),
            
            
            'sectionFavoritos'          => $sections['sectionFavoritos'] ?? [],    #        
            'sectionPorFavoritos'       => $sections['sectionPorFavoritos'] ?? [], #
            'sectionPorHistorico'       => $sections['sectionPorHistorico'] ?? [], #
            'sectionPorCategorias'      => $sections['sectionPorCategorias'] ?? [],
            'sectionPorBuscas'          => $sections['sectionPorBuscas'] ?? [], #
            'sectionPorClips'           => $sections['sectionPorClips'] ?? [], #
            'sectionPorMarcas'          => $sections['sectionPorMarcas'] ?? [],
            'sectionPorCarrinho'        => $sections['sectionPorCarrinho'] ?? [], #

            // 'streamSvc'                 =>$streamSvc
        ]);
    }

    public function newsletter(): void {
        $this->verifyCsrf();

        $email = SecurityHelper::sanitizeEmail($_POST['email'] ?? '');
        $nome  = SecurityHelper::sanitizeString($_POST['nome'] ?? '');

        if (!SecurityHelper::validateEmail($email)) {
            $this->json(['ok' => false, 'msg' => 'E-mail inválido.']);
        }

        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "INSERT INTO newsletter (email, nome, token_cancelamento)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE ativo = 1, nome = VALUES(nome)"
        );
        $stmt->execute([$email, $nome, SecurityHelper::generateToken(16)]);

        $this->json(['ok' => true, 'msg' => 'Cadastro realizado! Obrigado por se inscrever.']);
    }

    public function unsubscribe(string $token): void {
        $db = Database::getInstance()->getConnection();
        $db->prepare(
            "UPDATE newsletter SET ativo = 0 WHERE token_cancelamento = ?"
        )->execute([$token]);
        Session::flash('info', 'Você foi removido da nossa lista de e-mails.');
        $this->redirect(BASE_URL);
    }
}