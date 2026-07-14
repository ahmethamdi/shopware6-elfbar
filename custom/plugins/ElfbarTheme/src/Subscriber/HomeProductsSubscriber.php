<?php declare(strict_types=1);

namespace ElfbarTheme\Subscriber;

use Shopware\Core\Content\Category\Service\NavigationLoaderInterface;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
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
 */
class HomeProductsSubscriber implements EventSubscriberInterface
{
    private const LIMIT = 8;
    // 3 seviye: ana kategori → alt kategori → alt-altın altı (3. seviye).
    private const NAV_DEPTH = 3;

    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly NavigationLoaderInterface $navigationLoader
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
