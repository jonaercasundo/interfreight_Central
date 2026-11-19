<?php

/*
 * Versioned IFC starter directory. Keep codes sourced from UN/LOCODE and IATA
 * reference data; site-specific terminals must use their own approved IDs.
 * Updating this file is the controlled reference-data change mechanism.
 */
return [
    'release' => '2025-1',
    'countries' => [
        'PH' => 'Philippines', 'CN' => 'China', 'VN' => 'Vietnam', 'SG' => 'Singapore',
        'TH' => 'Thailand', 'MY' => 'Malaysia', 'ID' => 'Indonesia', 'JP' => 'Japan',
        'KR' => 'South Korea', 'HK' => 'Hong Kong', 'TW' => 'Taiwan',
    ],
    'locations' => [
        // Philippines
        ['country' => 'PH', 'name' => 'Manila', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'PHMNL'],
        ['country' => 'PH', 'name' => 'Cebu', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'PHCEB'],
        ['country' => 'PH', 'name' => 'Manila North Harbour', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'PHMNN'],
        ['country' => 'PH', 'name' => 'Manila South Harbour', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'PHMNS'],
        ['country' => 'PH', 'name' => 'Manila', 'type' => 'airport', 'system' => 'iata', 'code' => 'MNL'],
        ['country' => 'PH', 'name' => 'Mactan-Cebu', 'type' => 'airport', 'system' => 'iata', 'code' => 'CEB'],
        ['country' => 'PH', 'name' => 'Davao', 'type' => 'airport', 'system' => 'iata', 'code' => 'DVO'],
        ['country' => 'PH', 'name' => 'Manila', 'type' => 'city', 'system' => 'unlocode', 'code' => 'PHMNL'],
        ['country' => 'PH', 'name' => 'Cebu', 'type' => 'city', 'system' => 'unlocode', 'code' => 'PHCEB'],
        ['country' => 'PH', 'name' => 'Clark', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'PHKLR'],
        // China
        ['country' => 'CN', 'name' => 'Shanghai', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'CNSHA'],
        ['country' => 'CN', 'name' => 'Shenzhen', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'CNSZX'],
        ['country' => 'CN', 'name' => 'Ningbo', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'CNNGB'],
        ['country' => 'CN', 'name' => 'Qingdao', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'CNTAO'],
        ['country' => 'CN', 'name' => 'Shanghai Pudong', 'type' => 'airport', 'system' => 'iata', 'code' => 'PVG'],
        ['country' => 'CN', 'name' => 'Shenzhen Bao’an', 'type' => 'airport', 'system' => 'iata', 'code' => 'SZX'],
        ['country' => 'CN', 'name' => 'Guangzhou Baiyun', 'type' => 'airport', 'system' => 'iata', 'code' => 'CAN'],
        ['country' => 'CN', 'name' => 'Beijing Capital', 'type' => 'airport', 'system' => 'iata', 'code' => 'PEK'],
        ['country' => 'CN', 'name' => 'Shanghai', 'type' => 'city', 'system' => 'unlocode', 'code' => 'CNSHA'],
        ['country' => 'CN', 'name' => 'Shenzhen', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'CNSZX'],
        ['country' => 'CN', 'name' => 'Ningbo', 'type' => 'city', 'system' => 'unlocode', 'code' => 'CNNGB'],
        // Vietnam
        ['country' => 'VN', 'name' => 'Ho Chi Minh City', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'VNSGN'],
        ['country' => 'VN', 'name' => 'Hai Phong', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'VNHPH'],
        ['country' => 'VN', 'name' => 'Ho Chi Minh City', 'type' => 'airport', 'system' => 'iata', 'code' => 'SGN'],
        ['country' => 'VN', 'name' => 'Hanoi', 'type' => 'airport', 'system' => 'iata', 'code' => 'HAN'],
        ['country' => 'VN', 'name' => 'Da Nang', 'type' => 'airport', 'system' => 'iata', 'code' => 'DAD'],
        ['country' => 'VN', 'name' => 'Ho Chi Minh City', 'type' => 'city', 'system' => 'unlocode', 'code' => 'VNSGN'],
        ['country' => 'VN', 'name' => 'Hanoi', 'type' => 'city', 'system' => 'unlocode', 'code' => 'VNHAN'],
        ['country' => 'VN', 'name' => 'Hai Phong', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'VNHPH'],
        // Singapore
        ['country' => 'SG', 'name' => 'Singapore', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'SGSIN'],
        ['country' => 'SG', 'name' => 'Singapore Container Terminal', 'type' => 'container_terminal', 'system' => 'unlocode', 'code' => 'SGSCT'],
        ['country' => 'SG', 'name' => 'Singapore Changi', 'type' => 'airport', 'system' => 'iata', 'code' => 'SIN'],
        ['country' => 'SG', 'name' => 'Singapore', 'type' => 'city', 'system' => 'unlocode', 'code' => 'SGSIN'],
        ['country' => 'SG', 'name' => 'Singapore', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'SGSIN'],
        // Thailand
        ['country' => 'TH', 'name' => 'Bangkok', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'THBKK'],
        ['country' => 'TH', 'name' => 'Laem Chabang', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'THLCH'],
        ['country' => 'TH', 'name' => 'Bangkok Suvarnabhumi', 'type' => 'airport', 'system' => 'iata', 'code' => 'BKK'],
        ['country' => 'TH', 'name' => 'Bangkok Don Mueang', 'type' => 'airport', 'system' => 'iata', 'code' => 'DMK'],
        ['country' => 'TH', 'name' => 'Phuket', 'type' => 'airport', 'system' => 'iata', 'code' => 'HKT'],
        ['country' => 'TH', 'name' => 'Bangkok', 'type' => 'city', 'system' => 'unlocode', 'code' => 'THBKK'],
        ['country' => 'TH', 'name' => 'Laem Chabang', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'THLCH'],
        // Malaysia
        ['country' => 'MY', 'name' => 'Port Klang', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'MYPKG'],
        ['country' => 'MY', 'name' => 'Penang', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'MYPEN'],
        ['country' => 'MY', 'name' => 'Kuantan', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'MYKUA'],
        ['country' => 'MY', 'name' => 'Kuala Lumpur International', 'type' => 'airport', 'system' => 'iata', 'code' => 'KUL'],
        ['country' => 'MY', 'name' => 'Penang International', 'type' => 'airport', 'system' => 'iata', 'code' => 'PEN'],
        ['country' => 'MY', 'name' => 'Johor Bahru Senai', 'type' => 'airport', 'system' => 'iata', 'code' => 'JHB'],
        ['country' => 'MY', 'name' => 'Kuala Lumpur', 'type' => 'city', 'system' => 'unlocode', 'code' => 'MYKUL'],
        ['country' => 'MY', 'name' => 'Port Klang', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'MYPKG'],
        // Indonesia
        ['country' => 'ID', 'name' => 'Jakarta', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'IDJKT'],
        ['country' => 'ID', 'name' => 'Surabaya', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'IDSUB'],
        ['country' => 'ID', 'name' => 'Belawan', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'IDBLW'],
        ['country' => 'ID', 'name' => 'Jakarta Soekarno-Hatta', 'type' => 'airport', 'system' => 'iata', 'code' => 'CGK'],
        ['country' => 'ID', 'name' => 'Surabaya Juanda', 'type' => 'airport', 'system' => 'iata', 'code' => 'SUB'],
        ['country' => 'ID', 'name' => 'Bali Ngurah Rai', 'type' => 'airport', 'system' => 'iata', 'code' => 'DPS'],
        ['country' => 'ID', 'name' => 'Jakarta', 'type' => 'city', 'system' => 'unlocode', 'code' => 'IDJKT'],
        ['country' => 'ID', 'name' => 'Surabaya', 'type' => 'city', 'system' => 'unlocode', 'code' => 'IDSUB'],
        // Japan
        ['country' => 'JP', 'name' => 'Tokyo', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'JPTYO'],
        ['country' => 'JP', 'name' => 'Yokohama', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'JPYOK'],
        ['country' => 'JP', 'name' => 'Osaka', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'JPOSA'],
        ['country' => 'JP', 'name' => 'Tokyo Narita', 'type' => 'airport', 'system' => 'iata', 'code' => 'NRT'],
        ['country' => 'JP', 'name' => 'Tokyo Haneda', 'type' => 'airport', 'system' => 'iata', 'code' => 'HND'],
        ['country' => 'JP', 'name' => 'Osaka Kansai', 'type' => 'airport', 'system' => 'iata', 'code' => 'KIX'],
        ['country' => 'JP', 'name' => 'Tokyo', 'type' => 'city', 'system' => 'unlocode', 'code' => 'JPTYO'],
        ['country' => 'JP', 'name' => 'Yokohama', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'JPYOK'],
        // South Korea
        ['country' => 'KR', 'name' => 'Busan', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'KRPUS'],
        ['country' => 'KR', 'name' => 'Incheon', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'KRINC'],
        ['country' => 'KR', 'name' => 'Incheon International', 'type' => 'airport', 'system' => 'iata', 'code' => 'ICN'],
        ['country' => 'KR', 'name' => 'Busan Gimhae', 'type' => 'airport', 'system' => 'iata', 'code' => 'PUS'],
        ['country' => 'KR', 'name' => 'Seoul Gimpo', 'type' => 'airport', 'system' => 'iata', 'code' => 'GMP'],
        ['country' => 'KR', 'name' => 'Busan', 'type' => 'city', 'system' => 'unlocode', 'code' => 'KRPUS'],
        ['country' => 'KR', 'name' => 'Incheon', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'KRINC'],
        // Hong Kong
        ['country' => 'HK', 'name' => 'Hong Kong', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'HKHKG'],
        ['country' => 'HK', 'name' => 'Hong Kong International', 'type' => 'airport', 'system' => 'iata', 'code' => 'HKG'],
        ['country' => 'HK', 'name' => 'Hong Kong', 'type' => 'city', 'system' => 'unlocode', 'code' => 'HKHKG'],
        ['country' => 'HK', 'name' => 'Hong Kong', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'HKHKG'],
        // Taiwan
        ['country' => 'TW', 'name' => 'Kaohsiung', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'TWKHH'],
        ['country' => 'TW', 'name' => 'Taichung', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'TWTXG'],
        ['country' => 'TW', 'name' => 'Keelung', 'type' => 'seaport', 'system' => 'unlocode', 'code' => 'TWKEL'],
        ['country' => 'TW', 'name' => 'Taiwan Taoyuan', 'type' => 'airport', 'system' => 'iata', 'code' => 'TPE'],
        ['country' => 'TW', 'name' => 'Kaohsiung International', 'type' => 'airport', 'system' => 'iata', 'code' => 'KHH'],
        ['country' => 'TW', 'name' => 'Taipei', 'type' => 'city', 'system' => 'unlocode', 'code' => 'TWTPE'],
        ['country' => 'TW', 'name' => 'Kaohsiung', 'type' => 'logistics_hub', 'system' => 'unlocode', 'code' => 'TWKHH'],
    ],
];
