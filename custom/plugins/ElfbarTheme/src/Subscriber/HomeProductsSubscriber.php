<?php declare(strict_types=1);

namespace ElfbarTheme\Subscriber;

use Shopware\Core\Content\Category\Service\NavigationLoaderInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Storefront\Page\Navigation\NavigationPageLoadedEvent;
use Shopware\Storefront\Theme\ThemeConfigValueAccessor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * ElfbarTheme anasayfa + navigasyon beslemesi:
 *  1) Kategori AĞACI (her navigasyon sayfasında): Home root'tan dinamik çekilip
 *     'elfbarNavTree' extension olarak verilir → mega menü + anasayfa kategori
 *     vitrini artık HARDCODED ID yerine canlı navigasyondan beslenir (Mike'ın
 *     ortamında ID'ler farklı olsa da çalışır).
 *  2) Bestseller / Neuheiten ürünleri (sadece anasayfada): Home ağacından
 *     'elfbarBestseller' / 'elfbarNeu' extension. B2B login-gate'i Twig hallediyor.
 *  3) Hero-Slider (sadece anasayfada): her slaytın panelde girilen kategorisinden
 *     3 ürün → 'elfbarHeroSlides' extension (slayt no → ürün listesi + kategori).
 *  4) Linkler (sadece anasayfada) → 'elfbarHomeLinks': Bestseller-Kategorie
 *     (Panel "Bestseller Kategorie-ID", Name oder ID) + Kategorie-Karten ohne
 *     eigenen Link → Kategorie mit gleichem Namen (Korrektur 08.10.2026:
 *     "Alle anzeigen" führte zur Startseite, Karten waren nicht klickbar).
 */
class HomeProductsSubscriber implements EventSubscriberInterface
{
    private const LIMIT = 8;
    // 3 seviye: ana kategori → alt kategori → alt-altın altı (3. seviye).
    private const NAV_DEPTH = 3;
    // Hero-Slider: slayt sayısı (theme.json elfbar-heroslide-1..4-*) ve slayt başına ürün.
    private const HERO_SLIDES = 4;
    private const HERO_PRODUCTS = 3;
    // Kategorie-Karten (theme.json elfbar-catscroll-1..8-*)
    private const CATSCROLL_SLOTS = 8;

    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly NavigationLoaderInterface $navigationLoader,
        private readonly SalesChannelRepository $categoryRepository,
        private readonly ThemeConfigValueAccessor $themeConfig
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
            return;
        }

        $themeId = $event->getRequest()->attributes->get(SalesChannelRequest::ATTRIBUTE_THEME_ID);
        $themeId = \is_string($themeId) ? $themeId : null;

        // Bestseller-Kategorie aus dem Panel (leer = ganzer Shop, dann kein "Alle anzeigen")
        $bestRef = trim((string) $this->themeValue('elfbar-bestseller-category', $context, $themeId));
        $bestCategoryId = $bestRef !== '' ? $this->resolveCategoryId($context, $bestRef) : null;

        // Bestseller: en çok satan. Neuheiten: en yeni (createdAt — releaseDate çoğu üründe boş).
        $bestseller = $this->loadProducts($context, $bestCategoryId ?? $homeId, [
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

        $page->addExtension('elfbarHeroSlides', new ArrayStruct($this->loadHeroSlides($context, $themeId)));

        $catscroll = [];
        for ($i = 1; $i <= self::CATSCROLL_SLOTS; ++$i) {
            $prefix = 'elfbar-catscroll-' . $i . '-';
            $title = trim((string) $this->themeValue($prefix . 'title', $context, $themeId));
            if ($title === '' || trim((string) $this->themeValue($prefix . 'link', $context, $themeId)) !== '') {
                continue;
            }
            $id = $this->resolveCategoryId($context, $title) ?? $this->resolveCategoryByWords($context, $title);
            if ($id !== null) {
                $catscroll[$i] = $id;
            }
        }
        $page->addExtension('elfbarHomeLinks', new ArrayStruct([
            'bestsellerCategoryId' => $bestCategoryId,
            'catscroll' => $catscroll,
        ]));
    }

    /**
     * Ungefährer Treffer, wenn kein Kategoriename exakt passt: alle Wörter
     * kommen im Namen vor ("Lost Mary Nicsalt" → "Lost Mary Liquid (NicSalt)").
     */
    private function resolveCategoryByWords(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, string $title): ?string
    {
        $words = array_filter(preg_split('/[\s()]+/u', $title) ?: [], static fn (string $w) => mb_strlen($w) >= 2);
        if (!$words) {
            return null;
        }
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('active', true));
        foreach ($words as $word) {
            $criteria->addFilter(new ContainsFilter('name', $word));
        }
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $id = $this->categoryRepository->searchIds($criteria, $context)->firstId();

        return \is_string($id) ? $id : null;
    }

    /**
     * Her slayt için önce panelde girilen ürün numaralarını (product-1..3, sırası
     * korunur), boş kalan yerler için kategoriden (ad VEYA ID) en çok satanları
     * yükler (önce kapak görselli ana ürünler).
     * Kategori ADI desteklenir çünkü canlı ve lokalde ID'ler farklı.
     *
     * ⚠️ Hafif tut: slayt başına limit 3, sadece cover — tam ürün entity'si
     * ağırdır (Vapor /Aktionen RAM dersi).
     *
     * @return array<int, array{products: list<SalesChannelProductEntity>, categoryId: string|null}>
     */
    private function loadHeroSlides(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, ?string $themeId): array
    {
        $slides = [];
        for ($n = 1; $n <= self::HERO_SLIDES; ++$n) {
            $slides[$n] = ['products' => [], 'categoryId' => null];
            $prefix = 'elfbar-heroslide-' . $n . '-';

            // 1) Im Panel fest gewaehlte Produkte (Produktnummern, Reihenfolge bleibt)
            $numbers = [];
            for ($i = 1; $i <= self::HERO_PRODUCTS; ++$i) {
                $number = trim((string) $this->themeValue($prefix . 'product-' . $i, $context, $themeId));
                if ($number !== '') {
                    $numbers[] = $number;
                }
            }
            $products = $numbers ? $this->loadProductsByNumber($context, $numbers) : [];

            // 2) Freie Plaetze aus der Kategorie (Name oder ID) auffuellen
            $ref = trim((string) $this->themeValue($prefix . 'category', $context, $themeId));
            $categoryId = $ref !== '' ? $this->resolveCategoryId($context, $ref) : null;

            if ($categoryId !== null && \count($products) < self::HERO_PRODUCTS) {
                // Erst Produkte MIT Bild (der Hero-Kachel ist ein Bild), dann bei
                // Bedarf ohne Bild auffuellen (Platzhalter-Icon im Template).
                foreach ([true, false] as $withCover) {
                    $missing = self::HERO_PRODUCTS - \count($products);
                    if ($missing <= 0) {
                        break;
                    }
                    $products = array_merge($products, $this->loadHeroProducts(
                        $context,
                        $categoryId,
                        $missing,
                        $withCover,
                        array_map(static fn ($p) => $p->getId(), $products)
                    ));
                }
            }

            $slides[$n] = ['products' => $products, 'categoryId' => $categoryId];
        }

        return $slides;
    }

    private function themeValue(string $key, \Shopware\Core\System\SalesChannel\SalesChannelContext $context, ?string $themeId): mixed
    {
        try {
            return $this->themeConfig->get($key, $context, $themeId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Produkte per Produktnummer, in der eingegebenen Reihenfolge. Unbekannte
     * oder nicht sichtbare Nummern fallen still weg (der Platz wird dann aus
     * der Kategorie gefuellt). Varianten-Nummern sind erlaubt.
     *
     * @param list<string> $numbers
     *
     * @return list<SalesChannelProductEntity>
     */
    private function loadProductsByNumber(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, array $numbers): array
    {
        $criteria = new Criteria();
        $criteria->setLimit(\count($numbers));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $numbers));
        $criteria->addAssociation('cover');

        $byNumber = [];
        foreach ($this->productRepository->search($criteria, $context)->getEntities() as $product) {
            $byNumber[mb_strtolower((string) $product->getProductNumber())] = $product;
        }

        $sorted = [];
        foreach ($numbers as $number) {
            $product = $byNumber[mb_strtolower($number)] ?? null;
            if ($product !== null && !\in_array($product, $sorted, true)) {
                $sorted[] = $product;
            }
        }

        return $sorted;
    }

    /**
     * @param list<string> $excludeIds
     *
     * @return list<SalesChannelProductEntity>
     */
    private function loadHeroProducts(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, string $categoryId, int $limit, bool $withCover, array $excludeIds): array
    {
        $criteria = new Criteria();
        $criteria->setLimit($limit);
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('parentId', null));
        $criteria->addFilter(new EqualsFilter('categoriesRo.id', $categoryId));
        $coverNull = new EqualsFilter('coverId', null);
        $criteria->addFilter($withCover ? new NotFilter(NotFilter::CONNECTION_AND, [$coverNull]) : $coverNull);
        if ($excludeIds) {
            $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsAnyFilter('id', $excludeIds)]));
        }
        $criteria->addSorting(new FieldSorting('sales', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->addAssociation('cover');

        return array_values($this->productRepository->search($criteria, $context)->getEntities()->getElements());
    }

    private function resolveCategoryId(\Shopware\Core\System\SalesChannel\SalesChannelContext $context, string $ref): ?string
    {
        if (Uuid::isValid(strtolower($ref))) {
            return strtolower($ref);
        }

        // Ada göre (büyük/küçük harf duyarsız; DB collation'ı zaten ci).
        // Aynı ad birden fazla kategoride varsa: en üst seviyedeki.
        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('name', $ref));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));

        $id = $this->categoryRepository->searchIds($criteria, $context)->firstId();

        return \is_string($id) ? $id : null;
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
