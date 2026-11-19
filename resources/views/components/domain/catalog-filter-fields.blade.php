@props(['filters', 'prefix'])

<x-ui.select name="price" :id="$prefix.'-price'" label="Rentang Harga" :value="$filters['price']" :options="[
    '' => 'Semua harga',
    'under_100k' => '< Rp100.000',
    '100k_250k' => 'Rp100.000–Rp250.000',
    'over_250k' => '> Rp250.000',
]"/>
<x-ui.select name="duration" :id="$prefix.'-duration'" label="Pengerjaan" :value="$filters['duration']" :options="[
    '' => 'Semua durasi', 3 => '≤ 3 hari', 7 => '≤ 7 hari',
]"/>
<x-ui.select name="sort" :id="$prefix.'-sort'" label="Urutkan" :value="$filters['sort']" :options="[
    'newest' => 'Terbaru', 'rating' => 'Rating Seller tertinggi', 'price_asc' => 'Harga rendah', 'price_desc' => 'Harga tinggi',
]"/>
