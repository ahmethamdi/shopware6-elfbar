<?php declare(strict_types=1);

namespace ElfbarTheme\Controller;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Rechnungen & Dokumente" — müşterinin TÜM siparişlerindeki belgeleri
 * (Rechnung/Lieferschein/Gutschrift/Storno) tek listede toplar.
 * İndirme Shopware'in kendi route'uyla (frontend.account.order.single.document).
 * Belgeler admin/Plenty tarafından üretilir; demo ortamda boş olabilir.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
class DocumentsController extends StorefrontController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly GenericPageLoaderInterface $genericPageLoader
    ) {
    }

    #[Route(
        path: '/account/dokumente',
        name: 'frontend.account.elfbar.documents.page',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        methods: [Request::METHOD_GET]
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        $page = $this->genericPageLoader->load($request, $context);

        $customer = $context->getCustomer();
        $documents = $customer ? $this->loadDocuments($customer->getId(), $context->getLanguageId()) : [];

        return $this->renderStorefront('@Storefront/storefront/page/account/elfbar-documents.html.twig', [
            'page' => $page,
            'elfbarDocuments' => $documents,
        ]);
    }

    /**
     * @return list<array<string, string>>
     */
    private function loadDocuments(string $customerId, string $languageId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(d.id)) AS document_id,
                    d.document_number,
                    d.deep_link_code,
                    dt.technical_name AS type_technical,
                    COALESCE(dtt.name, dt.technical_name) AS type_name,
                    o.order_number,
                    d.created_at
             FROM document d
             INNER JOIN `order` o
                 ON o.id = d.order_id AND o.version_id = d.order_version_id
             INNER JOIN order_customer oc
                 ON oc.order_id = o.id AND oc.order_version_id = o.version_id
             INNER JOIN document_type dt
                 ON dt.id = d.document_type_id
             LEFT JOIN document_type_translation dtt
                 ON dtt.document_type_id = dt.id AND dtt.language_id = :languageId
             WHERE oc.customer_id = :customerId
             ORDER BY d.created_at DESC',
            [
                'customerId' => Uuid::fromHexToBytes($customerId),
                'languageId' => Uuid::fromHexToBytes($languageId),
            ]
        );
    }
}
