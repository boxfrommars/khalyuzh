<?php

declare(strict_types=1);

return [
    'databasePath' => dirname(__DIR__) . '/storage/records.sqlite',
    'timezone' => 'Asia/Yerevan',
    'pet' => [
        'name' => 'Халюж',
        'species' => 'Домашняя кошка',
        'breed' => 'Метис',
        'sex' => 'Самец',
        'reproductiveStatus' => 'Кастрирован',
        'diagnosis' => 'ХПН 1-2 стадия',
        'coatColor' => 'Голубой табби с белым',
        'birthDate' => '2016-04-08',
    ],
    'profile' => [
        'catWeight' => 5.5,
        'dryName' => 'Farmina VetLife Renal Dry',
        'dryCaloriesPerGram' => 4.421,
        'wetName' => 'Farmina VetLife Renal Wet',
        'wetCaloriesPerCan' => 102.2,
        'targetMin' => 255,
        'targetMax' => 330,
    ],
];
