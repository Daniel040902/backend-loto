<?php

namespace App\Services;

interface LotteryScraper
{
    public function getCountrySlug(): string;

    public function getApiBaseUrl(): string;

    public function getCalendarBaseUrl(): ?string;

    public function fetchResults(\DateTime $date): array;

    public function parseResult(array $rawData, string $gameName, string $drawTime): ?array;

    public function normalizeNumber(string $number): string;
}
