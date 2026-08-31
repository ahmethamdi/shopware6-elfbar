<?php declare(strict_types=1);

namespace ElfbarTheme\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Legt die Theme-Custom-Fields an, ohne dass das Plugin neu aktiviert
 * werden muss (das Deaktivieren wuerde das Theme kurzzeitig abschalten).
 *
 * Dieselbe Definition steht in ElfbarTheme::ensureCustomFields() — dort
 * greift sie bei einer frischen Installation (auch live). Dieser Befehl
 * ist der Weg fuer bereits laufende Installationen.
 */
#[AsCommand(
    name: 'elfbar:ensure-custom-fields',
    description: 'Legt die Custom-Fields des ElfbarTheme an (idempotent).'
)]
class EnsureCustomFieldsCommand extends Command
{
    private const FIELD_SET = 'elfbar_theme_fields';

    public function __construct(private readonly EntityRepository $customFieldSetRepository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $context = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::FIELD_SET));
        $criteria->setLimit(1);

        if ($this->customFieldSetRepository->searchIds($criteria, $context)->getTotal() > 0) {
            $output->writeln('<comment>Feld-Set existiert bereits — nichts zu tun.</comment>');

            return Command::SUCCESS;
        }

        $this->customFieldSetRepository->create([[
            'name' => self::FIELD_SET,
            'config' => [
                'label' => [
                    'de-DE' => 'ELFBAR Theme',
                    'en-GB' => 'ELFBAR theme',
                ],
                'translated' => true,
            ],
            'relations' => [
                ['entityName' => 'product'],
                ['entityName' => 'customer'],
            ],
            'customFields' => [
                [
                    'name' => 'external_product_link',
                    'type' => 'text',
                    'config' => [
                        'label' => [
                            'de-DE' => 'Produkt Ersatz-URL (Vitrinen-Produkt)',
                            'en-GB' => 'External product link (showcase product)',
                        ],
                        'helpText' => [
                            'de-DE' => 'Ist dieses Feld gefuellt, ist der Artikel nicht kaufbar: '
                                . 'kein Preis, kein Bestand, kein Warenkorb — stattdessen fuehrt '
                                . 'der Klick auf die hinterlegte Adresse ("Anfragen").',
                        ],
                        'componentName' => 'sw-field',
                        'customFieldType' => 'text',
                        'customFieldPosition' => 1,
                    ],
                ],
                [
                    'name' => 'customer_invoice_mail_field',
                    'type' => 'text',
                    'config' => [
                        'label' => [
                            'de-DE' => 'Rechnung an abweichende E-Mail-Adresse',
                            'en-GB' => 'Invoice to different e-mail address',
                        ],
                        'helpText' => [
                            'de-DE' => 'Wird im Adressformular vom Kunden selbst gepflegt und '
                                . 'vom Connector ausgelesen. Technischer Name bitte nicht aendern.',
                        ],
                        'componentName' => 'sw-field',
                        'customFieldType' => 'text',
                        'customFieldPosition' => 2,
                    ],
                ],
            ],
        ]], $context);

        $output->writeln('<info>Custom-Fields angelegt:</info>');
        $output->writeln('  · external_product_link (product)');
        $output->writeln('  · customer_invoice_mail_field (customer)');

        return Command::SUCCESS;
    }
}
