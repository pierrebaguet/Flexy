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

use FlexyBundle\Components\Organisms\LangSelect\Base as LangSelect;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;

/**
 * The links of the language selector stay on the page being read, whatever the path.
 */
final class LangSelectSwitchUrlsTest extends KernelTestCase
{
    public function testAPathWithTwoLeadingSlashesGivesALinkOnTheSameSite(): void
    {
        $switchUrls = $this->switchUrlsFor('//other.example.test/contact?page=2');

        self::assertNotSame([], $switchUrls);

        foreach ($switchUrls as $locale => $url) {
            self::assertSame('/other.example.test/contact?page=2&lang='.$locale, $url);
        }
    }

    public function testTheQueryIsKeptAndTheLanguageReplaced(): void
    {
        $switchUrls = $this->switchUrlsFor('/sofas.html?page=2&lang=fr_FR');

        foreach ($switchUrls as $locale => $url) {
            self::assertSame('/sofas.html?page=2&lang='.$locale, $url);
        }
    }

    /**
     * @return array<string, string>
     */
    private function switchUrlsFor(string $uri): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('https://localhost'.$uri);
        $request->setSession($session);

        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);

        try {
            return self::getContainer()->get(LangSelect::class)->getSwitchUrls();
        } finally {
            $requestStack->pop();
        }
    }
}
