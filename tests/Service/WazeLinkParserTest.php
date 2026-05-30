<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\WazeLinkParser;
use PHPUnit\Framework\TestCase;

class WazeLinkParserTest extends TestCase
{
    // --- extractHazardId ---

    public function testExtractHazardIdReturnsId(): void
    {
        $url = 'https://www.waze.com/pt-BR/editor?env=row&permanentHazards=98765';
        self::assertSame(98765, WazeLinkParser::extractHazardId($url));
    }

    public function testExtractHazardIdReturnsNullWhenAbsent(): void
    {
        self::assertNull(WazeLinkParser::extractHazardId('https://www.waze.com/editor'));
    }

    // --- extractVenueId ---

    public function testExtractVenueIdReturnsId(): void
    {
        $url = 'https://www.waze.com/pt-BR/editor?env=row&venues=ABC123';
        self::assertSame('ABC123', WazeLinkParser::extractVenueId($url));
    }

    public function testExtractVenueIdReturnsNullWhenAbsent(): void
    {
        self::assertNull(WazeLinkParser::extractVenueId('https://www.waze.com/editor'));
    }

    // --- extractCoords ---

    public function testExtractCoordsReturnsLatLon(): void
    {
        $url    = 'https://www.waze.com/pt-BR/editor?env=row&lat=-20.687&lon=-43.796&zoom=17';
        $coords = WazeLinkParser::extractCoords($url);
        self::assertNotNull($coords);
        self::assertEqualsWithDelta(-20.687, $coords['lat'], 0.0001);
        self::assertEqualsWithDelta(-43.796, $coords['lon'], 0.0001);
    }

    public function testExtractCoordsReturnsNullWhenAbsent(): void
    {
        self::assertNull(WazeLinkParser::extractCoords('https://www.waze.com/editor'));
    }

    // --- validators ---

    public function testIsValidHazardLink(): void
    {
        self::assertTrue(WazeLinkParser::isValidHazardLink('https://waze.com/editor?permanentHazards=1'));
        self::assertFalse(WazeLinkParser::isValidHazardLink('https://waze.com/editor'));
    }

    public function testIsValidPlaceLink(): void
    {
        self::assertTrue(WazeLinkParser::isValidPlaceLink('https://waze.com/editor?venues=XYZ'));
        self::assertFalse(WazeLinkParser::isValidPlaceLink('https://waze.com/editor'));
    }
}
