<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Tests\Component;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Api\Bridge\Propel\Extension\ContentPublicationExtension;
use Thelia\Test\IntegrationTestCase;

/**
 * A header entry named by its id alone reads its title and its address from the front API. When the API does not
 * serve the folder or the category (missing, hidden, not published), the entry is left out: the header goes on with
 * the others instead of drawing an entry without a title or a link.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class HeaderMenuItemUnservedTest extends IntegrationTestCase
{
    public function testAPublishedFolderIsDrawnFromTheApi(): void
    {
        $folder = $this->createFixtureFactory()->folder(0, ['title' => 'Our shops']);

        $item = $this->render('Folder', $folder->getId())->filter('.HeaderMenuItem');

        self::assertCount(1, $item);
        self::assertStringContainsString('Our shops', $item->text());
    }

    public function testAFolderTheApiDoesNotKnowIsLeftOut(): void
    {
        self::assertCount(0, $this->render('Folder', 999_999_999)->filter('.HeaderMenuItem'));
    }

    public function testAnUnpublishedFolderIsLeftOut(): void
    {
        if (!class_exists(ContentPublicationExtension::class)) {
            self::markTestSkipped('The core published does not keep unpublished folders off the front API yet.');
        }

        $folder = $this->createFixtureFactory()->folder(0, ['title' => 'Coming soon', 'visible' => 0]);

        self::assertCount(0, $this->render('Folder', $folder->getId())->filter('.HeaderMenuItem'));
    }

    public function testACategoryTheApiDoesNotKnowIsLeftOut(): void
    {
        self::assertCount(0, $this->render('Category', 999_999_999)->filter('.HeaderMenuItem'));
    }

    private function render(string $component, int $id): Crawler
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = $this->getService('ux.twig_component.component_renderer');

        return new Crawler('<ul>'.$renderer->createAndRender('Organisms:HeaderMenuItem:'.$component, ['id' => $id]).'</ul>');
    }
}
