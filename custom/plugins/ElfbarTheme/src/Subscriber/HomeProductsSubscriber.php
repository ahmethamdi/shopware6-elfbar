<?php declare(strict_types=1);

namespace ElfbarTheme\Subscriber;

use Shopware\Core\Content\Category\Service\NavigationLoaderInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * ElfbarTheme anasayfa + navigasyon beslemesi:
 *  1) Kategori AĞACI (her navigasyon sayfasında): Home root'tan dinamik çekilip
 *     'elfbarNavTree' extension olarak verilir → mega menü + anasayfa kategori
 *     vitrini artık HARDCODED ID yerine canlı navigasyondan beslenir (Mike'ın
 *     ortamında ID'ler farklı olsa da çalışır).
 *  2) Bestseller / Neuheiten ürünleri (sadece anasayfada): Home ağacından
 *     'elfbarBestseller' / 'elfbarNeu' extension. B2B login-gate'i Twig hallediyor.
 *  3) ALT KATEGORİ ŞERİDİ (kategori sayfalarında): açık kategorinin alt
 *     kategorileri 'elfbarSubcats' extension olarak verilir. Alt kategorisi
 *     olmayan YAPRAK kategorilerde bunun yerine KARDEŞ kategoriler gösterilir
 *     ("Lost Mary WAVI"e girildiğinde diğer Lost Mary kategorileri görünür).
 */
class HomeProductsSubscriber implements EventSubscriberInterface
{
    private const LIMIT = 8;
    // 3 seviye: ana kategori → alt kategori → alt-altın altı (3. seviye).
    private const NAV_DEPTH = 3;
    // Kapak görseli aranırken taranacak ürün havuzu (tek sorgu, N+1 yok).
    private const COVER_POOL = 250;

    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly NavigationLoaderInterface $navigationLoader,
        private readonly SalesChannelRepository $categoryRepository
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationPageLoadedEvent::class => 'onNavigationPageLoaded',
        ];
    }

    public function onNavigationPageLoaded(NavigationPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $context = $event->getSalesChannelContext();

        $homeId = $context->getSalesChannel()->getNavigationCategoryId();
        $currentNavId = $page->getNavigationId() ?? $homeId;

        // Kategori ağacı — HER navigasyon sayfasında (mega menü her yerde lazım).
        $this->addNavigationTree($page, $context, $homeId, $currentNavId);

        // Aşağısı sadece ANASAYFA (Home kategorisi açıkken).
        if ($currentNavId !== $homeId) {
            // Kategori sayfası → alt kategori (yoksa kardeş) şeridi.
            $this->addSubcategoryStrip($page, $context, $currentNavId);

            return;
        }

        // Bestseller: en çok satan. Neuheiten: en yeni (createdAt — releaseDate çoğu üründe boş).
        $bestseller = $this->loadProducts($context, $homeId, [
            new FieldSorting('sales', FieldSorting::DESCENDING),
            new FieldSorting('id', FieldSorting::ASCENDING),
        ]);
        $neu = $this->loadProducts($context, $homeId, [
            new FieldSorting('releaseDate', FieldSorting::DESCENDING),
            new FieldSorting('createdAt', FieldSorting::DESCENDING),
            new FieldSorting('id', FieldSorting::DESCENDING),
        ]);

        $page->addExtension('elfbarBestseller', new ArrayStruct(['products' => $bestseller]));
        $page->addExtension('elfbarNeu', new ArrayStruct(['products' => $neu]));
    }

    /**
     * Home root'un alt kategori ağacını (depth=2) çekip page extension verir.
     * Tree struct'ı Twig'de page.extensions.elfbarNavTree.tree ile gezilir
     * (core navbar'daki treeItem.category / treeItem.children ile aynı yapı).
     */
    private function addNavigationTree(
        \Shopware\Storefront\Page\Page $page,
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
        string $homeId,
        string $activeId
    ): void {
        try {
            $tree = $this->navigationLoader->load($activeId, $context, $homeId, self::NAV_DEPTH);
        } catch (\Throwable) {
            // Ağaç yüklenemezse Twig fallback (hardcoded elfbarCats) devreye girer.
            return;
        }

        $page->addExtension('elfbarNavTree', $tree);
    }

    /**
     * Kategori sayfasındaki yatay şerit: önce AÇIK kategorinin alt
     * kategorileri; yoksa (yaprak kategori) aynı ebeveynin altındaki
     * KARDEŞ kategoriler.
     *
     * Neden kardeş: "Lost Mary WAVI" gibi bir yaprağa girildiğinde
     * kullanıcı markanın diğer serilerini de görebilsin. Vapor bunu
     * kategori ADINDAN marka öneki tahmin ederek yapıyordu (sabit
     * "elf bar"/"al fakher"/... listesiyle). Burada bilinçli olarak
     * EBEVEYN üzerinden gidiliyor:
     *   - isim yazımına bağlı değil (ELFBAR / Elfbar / Elf Bar farketmez),
     *   - kategoriler yeniden adlandırılsa da çalışır,
     *   - sabit marka listesi bakım gerektirmez.
     * Ağaç zaten markaya göre kurulu (ELFBAR ELFX, Lost Mary, LIQUID …),
     * yani ebeveyn = marka/seri ailesi.
     *
     * Şerit "süs"tür: hata olursa sessizce atlanır, kategori sayfası
     * normal render olmaya devam eder.
     */
    private function addSubcategoryStrip(
        \Shopware\Storefront\Page\Page $page,
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
        string $navigationId
    ): void {
        try {
            $categories = $this->loadChildCategories($context, $navigationId);

            if ($categories === []) {
                // Yaprak kategori → kardeşleri göster (kendisi hariç).
                $categories = $this->loadSiblingCategories($context, $navigationId);
            }

            if ($categories === []) {
                return;
            }

            $covers = $this->loadCategoryCovers($context, array_keys($categories));

            $items = [];
            foreach ($categories as $id => $category) {
                // Kategorinin kendi görseli varsa o kazanır; yoksa üründen gelen kapak.
                $items[] = [
                    'category' => $category,
                    'media' => $category->getMedia() ?? ($covers[$id] ?? null),
                ];
            }

            $page->addExtension('elfbarSubcats', new ArrayStruct(['items' => $items]));
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Verilen kategorinin doğrudan alt kategorileri (aktif + menüde görünür).
     *
     * @return array<string, \Shopware\Core\Content\Category\CategoryEntity>
     */
    private function loadChildCategories(
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
        string $parentId
    ): array {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('parentId', $parentId));
        $criteria->addFilter(new EqualsFilter('active', true));
        // Menüde gizlenenler şeritte de görünmesin.
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->addAssociation('media');
        $criteria->addSorting(new FieldSorting('autoIncrement', FieldSorting::ASCENDING));

        return $this->categoryRepository->search($criteria, $context)->getEntities()->getElements();
    }

    /**
     * Yaprak kategori için KARDEŞLER: aynı ebeveynin diğer alt kategorileri.
     * Açık kategori listeden çıkarılır (kendisine link vermek anlamsız).
     *
     * @return array<string, \Shopware\Core\Content\Category\CategoryEntity>
     */
    private function loadSiblingCategories(
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
        string $navigationId
    ): array {
        $current = $this->categoryRepository
            ->search(new Criteria([$navigationId]), $context)
            ->getEntities()
            ->first();

        $parentId = $current?->getParentId();

        if ($parentId === null) {
            return [];
        }

        $siblings = $this->loadChildCategories($context, $parentId);
        unset($siblings[$navigationId]);

        return $siblings;
    }

    /**
     * Kategori başına kapak görseli: kategorinin kendi media'sı yoksa
     * içindeki (en çok satan) ürünün kapağı kullanılır.
     *
     * Tek sorgu + PHP tarafında eşleme → kategori başına ayrı sorgu (N+1) yok.
     *
     * @param list<string> $categoryIds
     *
     * @return array<string, \Shopware\Core\Content\Media\MediaEntity>
     */
    private function loadCategoryCovers(
        \Shopware\Core\System\SalesChannel\SalesChannelContext $context,
        array $categoryIds
    ): array {
        if ($categoryIds === []) {
            return [];
        }

        $criteria = new Criteria();
        // Kategori başına 1 görsel yeterli ama ürünler kategorilere dağıldığı
        // için havuz geniş tutulur; eşleme aşağıda PHP'de yapılır.
        $criteria->setLimit(self::COVER_POOL);
        $criteria->addFilter(new EqualsFilter('active', true));
        // Varyantlar hariç — ana ürünün kapağı temsil eder.
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addFilter(new EqualsAnyFilter('categoriesRo.id', $categoryIds));
        $criteria->addAssociation('cover');
        $criteria->addAssociation('categoriesRo');
        // En çok satan önce → her kategori en güçlü ürününün görselini alır.
        $criteria->addSorting(new FieldSorting('sales', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));

        $products = $this->productRepository->search($criteria, $context)->getEntities();

        $wanted = array_flip($categoryIds);
        $covers = [];

        foreach ($products as $product) {
            $media = $product->getCover()?->getMedia();

            if ($media === null) {
                continue;
            }

            // Ürün birden fazla kategoride olabilir; henüz görseli olmayan
            // her eşleşen kategoriye bu kapağı ver.
            foreach ($product->getCategoriesRo() ?? [] as $category) {
                $categoryId = $category->getId();

                if (isset($wanted[$categoryId]) && !isset($covers[$categoryId])) {
                    $covers[$categoryId] = $media;
                }
            }

            if (\count($covers) === \count($categoryIds)) {
                break;
            }
        }

        return $covers;
    }

    /**
     * @param list<FieldSorting> $sortings
     *
     * @return list<SalesChannelProductEntity>
     */
    private function loadProducts(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, string $categoryId, array $sortings): array
    {
        $criteria = new Criteria();
        $criteria->setLimit(self::LIMIT);
        $criteria->addFilter(new EqualsFilter('active', true));
        // Sadece ana ürünler — varyantlar listede tekrar oluşturmasın.
        $criteria->addFilter(new EqualsFilter('parentId', null));
        // categoriesRo = read-only çözümlenmiş kategoriler → Home'un TÜM alt ağacındaki ürünler dahil.
        $criteria->addFilter(new EqualsFilter('categoriesRo.id', $categoryId));
        foreach ($sortings as $sorting) {
            $criteria->addSorting($sorting);
        }
        $criteria->addAssociation('cover');
        $criteria->addAssociation('manufacturer');

        $result = $this->productRepository->search($criteria, $context)->getEntities()->getElements();

        return array_values($result);
    }
}
