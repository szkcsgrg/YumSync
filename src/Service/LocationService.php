<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LocationService
{
    private HttpClientInterface $httpClient;
    private LoggerInterface $logger;

    public function __construct(HttpClientInterface $httpClient, LoggerInterface $logger)
    {
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Detect user location from IP address using ip-api.com (free service)
     */
    public function detectLocationFromIP(?string $ipAddress = null): ?array
    {
        try {
            // If no IP provided, use a fallback (in production, you'd get this from request)
            if (!$ipAddress) {
                // For development, we'll use a sample IP or skip detection
                $this->logger->info('No IP address provided for location detection');
                return null;
            }

            $response = $this->httpClient->request('GET', "http://ip-api.com/json/{$ipAddress}", [
                'timeout' => 5,
                'headers' => [
                    'User-Agent' => 'YumSync-ShopSuggester/1.0'
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                
                if ($data['status'] === 'success') {
                    $this->logger->info('Location detected from IP', [
                        'ip' => $ipAddress,
                        'country' => $data['country'] ?? null,
                        'countryCode' => $data['countryCode'] ?? null,
                        'region' => $data['regionName'] ?? null,
                        'city' => $data['city'] ?? null
                    ]);

                    return [
                        'country' => $data['country'] ?? null,
                        'countryCode' => $data['countryCode'] ?? null,
                        'region' => $data['regionName'] ?? null,
                        'city' => $data['city'] ?? null,
                        'detectedFrom' => 'ip'
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to detect location from IP', [
                'ip' => $ipAddress,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Get suggested shops based on country/region
     */
    public function getSuggestedShops(string $countryCode, ?string $region = null): array
    {
        $shopDatabase = $this->getShopDatabase();
        
        $countryCode = strtoupper($countryCode);
        
        // First try to get region-specific shops
        if ($region && isset($shopDatabase[$countryCode]['regions'][$region])) {
            $shops = array_merge(
                $shopDatabase[$countryCode]['national'] ?? [],
                $shopDatabase[$countryCode]['regions'][$region] ?? []
            );
        } else {
            // Fall back to national shops
            $shops = $shopDatabase[$countryCode]['national'] ?? [];
        }

        // If no shops found for the country, provide generic international ones
        if (empty($shops)) {
            $shops = $shopDatabase['INTERNATIONAL']['national'] ?? [];
        }

        $this->logger->info('Shop suggestions generated', [
            'countryCode' => $countryCode,
            'region' => $region,
            'shopCount' => count($shops)
        ]);

        return array_values(array_unique($shops));
    }

    /**
     * Shop database organized by country and region
     */
    private function getShopDatabase(): array
    {
        return [
            'HU' => [ // Hungary
                'national' => [
                    'Tesco', 'Auchan', 'Lidl', 'Aldi', 'Spar', 'CBA', 'Penny Market',
                    'Real', 'Interspar', 'Match', 'Príma', 'Coop'
                ],
                'regions' => [
                    'Budapest' => ['Westend City Center', 'Arena Mall', 'Corvin Plaza'],
                    'Debrecen' => ['Forum Debrecen', 'Fórum Shopping Center'],
                    'Szeged' => ['Árkád Szeged', 'STOP.SHOP. Szeged']
                ]
            ],
            'AT' => [ // Austria
                'national' => [
                    'Billa', 'Merkur', 'Spar', 'Hofer', 'Lidl', 'Penny',
                    'Interspar', 'Eurospar', 'MPreis', 'Unimarkt'
                ],
                'regions' => [
                    'Vienna' => ['Naschmarkt', 'Wiener Stadthalle', 'Shopping City Süd'],
                    'Salzburg' => ['Europark', 'Alpenstraße Center'],
                    'Graz' => ['Shopping Center Seiersberg', 'Citypark']
                ]
            ],
            'DE' => [ // Germany
                'national' => [
                    'Lidl', 'Aldi', 'Rewe', 'Edeka', 'Netto', 'Penny',
                    'Real', 'Kaufland', 'Globus', 'Famila', 'Marktkauf'
                ],
                'regions' => [
                    'Berlin' => ['KaDeWe', 'Galeria Kaufhof', 'Alexa'],
                    'Munich' => ['Maximilianstrasse', 'Marienplatz', 'Quartier 206'],
                    'Hamburg' => ['Alsterhaus', 'Europa Passage']
                ]
            ],
            'US' => [ // United States
                'national' => [
                    'Walmart', 'Target', 'Kroger', 'Safeway', 'Whole Foods',
                    'Costco', 'Sam\'s Club', 'Meijer', 'Publix', 'H-E-B'
                ],
                'regions' => [
                    'California' => ['Trader Joe\'s', 'Ralphs', 'Vons', 'Smart & Final'],
                    'Texas' => ['H-E-B', 'Market Street', 'Brookshire\'s'],
                    'Florida' => ['Publix', 'Winn-Dixie', 'Fresh Market'],
                    'New York' => ['Key Food', 'Stop & Shop', 'ShopRite']
                ]
            ],
            'GB' => [ // United Kingdom
                'national' => [
                    'Tesco', 'Sainsbury\'s', 'ASDA', 'Morrisons', 'Lidl',
                    'Aldi', 'Co-op', 'Iceland', 'Waitrose', 'M&S Food'
                ],
                'regions' => [
                    'London' => ['Harrods Food Hall', 'Selfridges Food Hall', 'Borough Market'],
                    'Manchester' => ['Arndale Market', 'Trafford Centre'],
                    'Birmingham' => ['Bullring Markets', 'Grand Central']
                ]
            ],
            'FR' => [ // France
                'national' => [
                    'Carrefour', 'Leclerc', 'Intermarché', 'Super U', 'Auchan',
                    'Casino', 'Monoprix', 'Franprix', 'Lidl', 'Aldi'
                ],
                'regions' => [
                    'Paris' => ['Galeries Lafayette Gourmet', 'Le Bon Marché', 'Marché des Enfants Rouges'],
                    'Lyon' => ['Part-Dieu', 'Confluence'],
                    'Marseille' => ['Les Terrasses du Port', 'Grand Littoral']
                ]
            ],
            'ES' => [ // Spain
                'national' => [
                    'Mercadona', 'Carrefour', 'El Corte Inglés', 'Dia', 'Lidl',
                    'Aldi', 'Eroski', 'Consum', 'Caprabo', 'Alcampo'
                ],
                'regions' => [
                    'Madrid' => ['El Corte Inglés Castellana', 'Mercado de San Miguel'],
                    'Barcelona' => ['La Boquería', 'El Corte Inglés Catalunya'],
                    'Valencia' => ['Mercado Central', 'El Saler Centro Comercial']
                ]
            ],
            'IT' => [ // Italy
                'national' => [
                    'Coop', 'Esselunga', 'Carrefour', 'Conad', 'Eurospin',
                    'Lidl', 'MD Discount', 'Pam', 'Unes', 'Il Gigante'
                ],
                'regions' => [
                    'Rome' => ['Mercato di Testaccio', 'Porta Portese', 'Campo de\' Fiori'],
                    'Milan' => ['Eataly Milano', 'Quadrilatero della Moda'],
                    'Naples' => ['Mercato di Porta Nolana', 'Via dei Tribunali']
                ]
            ],
            'PL' => [ // Poland
                'national' => [
                    'Biedronka', 'Żabka', 'Carrefour', 'Tesco', 'Auchan',
                    'Lidl', 'Netto', 'Intermarché', 'Polomarket', 'Dino'
                ],
                'regions' => [
                    'Warsaw' => ['Hala Mirowska', 'Galeria Mokotów'],
                    'Krakow' => ['Galeria Krakowska', 'Stary Kleparz'],
                    'Gdansk' => ['Madison Gallery', 'Forum Gdansk']
                ]
            ],
            'CZ' => [ // Czech Republic
                'national' => [
                    'Albert', 'Tesco', 'Kaufland', 'Lidl', 'Penny Market',
                    'Billa', 'Globus', 'Coop', 'Flop TOP', 'CBA'
                ],
                'regions' => [
                    'Prague' => ['Havelské tržiště', 'Palladium', 'Wenceslas Square'],
                    'Brno' => ['Vaňkovka Gallery', 'Olympia Brno'],
                    'Ostrava' => ['Nová Karolina', 'Avion Shopping Park']
                ]
            ],
            'INTERNATIONAL' => [ // Fallback for unknown countries
                'national' => [
                    'Local Supermarket', 'Grocery Store', 'Corner Shop', 'Market',
                    'Food Store', 'Mini Market', 'Convenience Store', 'Hypermarket'
                ]
            ]
        ];
    }
}
