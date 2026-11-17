<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\PostComment;
use App\Models\PostReaction;
use App\Models\RestaurantOpeningHour;
use App\Models\RestaurantPhoto;
use App\Models\RestaurantPost;
use App\Models\RestaurantReview;
use App\Models\User;
use GdImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Demo data for the social network: restaurants with logo, banner, hours,
 * photos, posts in every format, and customers who react, comment and review.
 *
 *     php artisan db:seed --class=SocialDemoSeeder
 *
 * Every demo account uses an @demo.table4all.test email and the password
 * "password". Running it again replaces the demo data and nothing else.
 */
class SocialDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.table4all.test';

    private const IMAGE_DIR = 'demo';

    /** Post formats → width × height in px (Instagram sizes, see RestaurantPost::ASPECTS). */
    private const SIZES = [
        'portrait' => [1080, 1350],
        'square' => [1080, 1080],
        'landscape' => [1080, 566],
        'story' => [1080, 1920],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->error('SocialDemoSeeder does not run in production.');

            return;
        }

        mt_srand(42);
        $this->cleanUp();

        $customers = $this->customers();
        $restaurants = [];

        foreach ($this->restaurantData() as $data) {
            $restaurants[] = [$this->restaurant($data), $data];
        }

        $this->posts($restaurants, $customers);
        $this->reviews($restaurants, $customers);

        $this->command->info(sprintf(
            '%d restaurants, %d customers and %d posts created. Password for every demo account: "password".',
            count($restaurants),
            count($customers),
            RestaurantPost::whereIn('user_id', array_map(fn ($r) => $r[0]->id, $restaurants))->count(),
        ));
    }

    /** Removes the previous demo run: accounts (cascading their data) and generated images. */
    private function cleanUp(): void
    {
        $ids = User::where('email', 'like', '%@'.self::DOMAIN)->pluck('id');

        // Posts first so reactions/comments of non-demo users on demo posts go too (FK cascade).
        RestaurantPost::whereIn('user_id', $ids)->delete();
        User::whereIn('id', $ids)->delete();
        Storage::disk('public')->deleteDirectory(self::IMAGE_DIR);
    }

    /** @return list<User> */
    private function customers(): array
    {
        $people = ['Ana Souza', 'Bruno Lima', 'Carla Mendes', 'Diego Rocha', 'Elisa Prado', 'Felipe Nunes'];

        return array_map(function (string $name): User {
            $user = User::create([
                'name' => $name,
                'email' => Str::slug($name, '.').'@'.self::DOMAIN,
                'account_type' => AccountType::CUSTOMER,
                'password' => Hash::make('password'),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        }, $people);
    }

    /** @param  array<string, mixed>  $data */
    private function restaurant(array $data): User
    {
        [$from, $to] = $data['colors'];

        $restaurant = User::create([
            'name' => $data['name'],
            'email' => $data['handle'].'@'.self::DOMAIN,
            'account_type' => AccountType::RESTAURANT,
            'handle' => $data['handle'],
            'profile_public' => $data['public'] ?? true,
            'password' => Hash::make('password'),
            'logo' => $this->store('logos', $this->logo($data['initials'], $from, $to), 'png'),
            'banner' => $this->store('banners', $this->picture(1920, 640, $from, $to, $data['tagline']), 'jpg'),
        ]);
        $restaurant->forceFill(['email_verified_at' => now()])->save();

        $now = now();
        RestaurantOpeningHour::insert(array_map(fn (array $h): array => [
            'user_id' => $restaurant->id,
            'weekday' => $h[0],
            'opens_at' => $h[1],
            'closes_at' => $h[2],
            'created_at' => $now,
            'updated_at' => $now,
        ], $data['hours']));

        foreach (array_slice($data['photos'], 0, 4) as $i => $caption) {
            RestaurantPhoto::create([
                'user_id' => $restaurant->id,
                'path' => $this->store('venue', $this->picture(1600, 1200, $to, $from, $caption), 'jpg'),
                'position' => $i,
            ]);
        }

        return $restaurant;
    }

    /**
     * Posts of every restaurant, interleaved in time so the feed mixes them.
     * Inserted oldest first: the feed orders by id.
     *
     * @param  list<array{0: User, 1: array<string, mixed>}>  $restaurants
     * @param  list<User>  $customers
     */
    private function posts(array $restaurants, array $customers): void
    {
        $all = [];

        foreach ($restaurants as [$restaurant, $data]) {
            foreach ($data['posts'] as $post) {
                $all[] = [$restaurant, $data, $post];
            }
        }

        usort($all, fn ($a, $b) => $b[2]['hours_ago'] <=> $a[2]['hours_ago']);

        $comments = [
            'Que delícia! 😍', 'Vou levar a galera!', 'Melhor da região, sem dúvida.',
            'Tem opção vegetariana?', 'Partiu sexta!', 'Fui ontem e amei.',
            'Esse preço tá ótimo.', 'Aceita reserva?', 'Saudade desse lugar!',
        ];
        $emoji = ['🍺', '🔥', '⭐', '🎉', '😋', '🤤', '🍕', '👏'];

        foreach ($all as [$restaurant, $data, $post]) {
            $at = now()->subHours($post['hours_ago'])->subMinutes(mt_rand(0, 59));
            $aspect = $post['aspect'] ?? null;
            $image = null;

            if ($aspect !== null) {
                [$w, $h] = self::SIZES[$aspect];
                [$from, $to] = $data['colors'];
                $image = $this->store('posts', $this->picture($w, $h, $from, $to, $post['image_text']), 'jpg');
            }

            $model = RestaurantPost::create([
                'user_id' => $restaurant->id,
                'body' => $post['body'] ?? null,
                'image' => $image,
                'image_aspect' => $aspect,
            ]);
            $model->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();

            // Some customers react (one each) and a few comment.
            foreach ($customers as $customer) {
                if (mt_rand(1, 100) <= 65) {
                    PostReaction::create(['post_id' => $model->id, 'user_id' => $customer->id, 'emoji' => $emoji[mt_rand(0, count($emoji) - 1)]]);
                }
            }

            $count = mt_rand(0, 3);

            for ($i = 0; $i < $count; $i++) {
                $author = $customers[mt_rand(0, count($customers) - 1)];
                $comment = PostComment::create(['post_id' => $model->id, 'user_id' => $author->id, 'body' => $comments[mt_rand(0, count($comments) - 1)]]);
                $comment->forceFill(['created_at' => $at->copy()->addMinutes(mt_rand(5, 300))])->saveQuietly();
            }

            if ($count > 0 && mt_rand(0, 1) === 1) {
                PostComment::create(['post_id' => $model->id, 'user_id' => $restaurant->id, 'body' => 'Obrigado! Esperamos vocês 🙌']);
            }
        }
    }

    /**
     * @param  list<array{0: User, 1: array<string, mixed>}>  $restaurants
     * @param  list<User>  $customers
     */
    private function reviews(array $restaurants, array $customers): void
    {
        foreach ($restaurants as [$restaurant, $data]) {
            // The newest place has no reviews yet, to show the empty state.
            if (($data['reviews'] ?? true) === false) {
                continue;
            }

            foreach ($customers as $customer) {
                if (mt_rand(1, 100) <= 80) {
                    RestaurantReview::create([
                        'restaurant_id' => $restaurant->id,
                        'user_id' => $customer->id,
                        'rating' => max(1, min(5, $data['rating_base'] + mt_rand(-1, 1))),
                    ]);
                }
            }
        }
    }

    // ── images ───────────────────────────────────────────────────────────────

    private function store(string $folder, GdImage $image, string $type): string
    {
        $path = self::IMAGE_DIR.'/'.$folder.'/'.Str::random(24).'.'.$type;

        ob_start();
        $type === 'png' ? imagepng($image, null, 6) : imagejpeg($image, null, 82);
        Storage::disk('public')->put($path, (string) ob_get_clean());
        imagedestroy($image);

        return $path;
    }

    /**
     * Vertical gradient with soft circles and a caption: stands in for a real photo.
     *
     * @param  int<1, max>  $w
     * @param  int<1, max>  $h
     */
    private function picture(int $w, int $h, string $from, string $to, string $caption): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        [$r1, $g1, $b1] = $this->rgb($from);
        [$r2, $g2, $b2] = $this->rgb($to);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / max(1, $h - 1);
            $color = imagecolorallocate($img, $this->channel($r1 + ($r2 - $r1) * $t), $this->channel($g1 + ($g2 - $g1) * $t), $this->channel($b1 + ($b2 - $b1) * $t));
            imageline($img, 0, $y, $w, $y, (int) $color);
        }

        imagealphablending($img, true);

        for ($i = 0; $i < 7; $i++) {
            $alpha = mt_rand(85, 115);
            $circle = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
            $size = (int) (min($w, $h) * mt_rand(20, 60) / 100);
            imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, $h), $size, $size, (int) $circle);
        }

        $this->caption($img, $caption, $w, $h);

        return $img;
    }

    /** Rounded-square logo with the initials. */
    private function logo(string $initials, string $from, string $to): GdImage
    {
        $img = $this->picture(512, 512, $from, $to, '');
        // The built-in font is ASCII only ("É" would garble); fit the initials in ~70% of the width.
        $initials = Str::ascii($initials);
        $this->text($img, $initials, 512, 512, (int) floor(512 * 0.7 / (strlen($initials) * 9)));

        return $img;
    }

    /** Caption centered on the picture, in GD's built-in font scaled up (no FreeType in the container). */
    private function caption(GdImage $img, string $text, int $w, int $h): void
    {
        if ($text === '') {
            return;
        }

        $lines = explode("\n", wordwrap(Str::ascii($text), 18, "\n", true));
        $scale = max(2, (int) floor(min($w * 0.85 / (18 * 9), $h * 0.5 / (count($lines) * 16))));
        $lineH = 16 * $scale;
        $top = (int) (($h - count($lines) * $lineH) / 2);

        foreach ($lines as $i => $line) {
            $this->text($img, $line, $w, $lineH, $scale, $top + $i * $lineH);
        }
    }

    private function text(GdImage $img, string $text, int $w, int $h, int $scale, int $top = 0): void
    {
        $small = imagecreatetruecolor(max(1, strlen($text) * 9), 16);
        $transparent = imagecolorallocatealpha($small, 0, 0, 0, 127);
        imagealphablending($small, false);
        imagefill($small, 0, 0, (int) $transparent);
        imagesavealpha($small, true);
        imagestring($small, 5, 0, 0, $text, (int) imagecolorallocate($small, 255, 255, 255));

        $tw = imagesx($small) * $scale;
        $th = 16 * $scale;
        $x = (int) (($w - $tw) / 2);
        $y = $top > 0 ? $top : (int) (($h - $th) / 2);

        // Shadow, then the text, both scaled with nearest-neighbour so the pixels stay crisp.
        $shadow = imagecreatetruecolor(imagesx($small), 16);
        imagealphablending($shadow, false);
        imagefill($shadow, 0, 0, (int) imagecolorallocatealpha($shadow, 0, 0, 0, 127));
        imagesavealpha($shadow, true);
        imagestring($shadow, 5, 0, 0, $text, (int) imagecolorallocatealpha($shadow, 0, 0, 0, 70));

        imagealphablending($img, true);
        imagecopyresized($img, $shadow, $x + (int) ($scale / 2), $y + (int) ($scale / 2), 0, 0, $tw, $th, imagesx($small), 16);
        imagecopyresized($img, $small, $x, $y, 0, 0, $tw, $th, imagesx($small), 16);
        imagedestroy($small);
        imagedestroy($shadow);
    }

    /** @return int<0, 255> */
    private function channel(float $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }

    // ── content ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function restaurantData(): array
    {
        $nights = fn (string $open, string $close, array $days = [2, 3, 4, 5, 6]): array => array_map(fn (int $d) => [$d, $open, $close], $days);

        return [
            [
                'name' => 'Boteco do Seu Zé', 'handle' => 'boteco-do-seu-ze', 'initials' => 'ZÉ',
                'colors' => ['#1f7a4d', '#0b3d2e'], 'tagline' => 'Boteco raiz desde 1998', 'rating_base' => 5,
                'hours' => [...$nights('17:00', '01:00'), [6, '12:00', '02:00'], [0, '12:00', '18:00']],
                'photos' => ['Balcao', 'Petiscos', 'Mesas na calcada'],
                'posts' => [
                    ['hours_ago' => 2, 'aspect' => 'portrait', 'image_text' => 'Chopp em dobro', 'body' => "🍺 Hoje tem CHOPP EM DOBRO das 18h às 20h!\nChama a galera e garante a mesa na calçada."],
                    ['hours_ago' => 30, 'aspect' => 'story', 'image_text' => 'Samba de raiz sabado 20h', 'body' => 'Sábado tem roda de samba ao vivo a partir das 20h. Entrada franca! 🎶'],
                    ['hours_ago' => 75, 'body' => 'Bolinho de bacalhau saindo agora, quentinho. Quem vem? 😋'],
                    ['hours_ago' => 160, 'aspect' => 'square', 'image_text' => 'Porcao de torresmo', 'body' => 'Nova porção de torresmo de rolo. Crocante por fora, macio por dentro.'],
                ],
            ],
            [
                'name' => 'Pizzaria Forno a Lenha', 'handle' => 'forno-a-lenha', 'initials' => 'FL',
                'colors' => ['#c0392b', '#5e1a12'], 'tagline' => 'Pizza napolitana no forno a lenha', 'rating_base' => 4,
                'hours' => $nights('18:30', '23:30', [0, 2, 3, 4, 5, 6]),
                'photos' => ['Forno a lenha', 'Margherita', 'Salao'],
                'posts' => [
                    ['hours_ago' => 5, 'aspect' => 'square', 'image_text' => 'Terca da Margherita', 'body' => 'Toda terça: Margherita grande com 30% OFF 🍕 Só no salão.'],
                    ['hours_ago' => 50, 'aspect' => 'landscape', 'image_text' => 'Rodizio de pizzas', 'body' => 'Rodízio de pizzas às quintas, com sobremesas inclusas. Reserve pelo WhatsApp!'],
                    ['hours_ago' => 120, 'body' => 'Massa de fermentação natural, 48h de descanso. Dá pra sentir a diferença. 👨‍🍳'],
                ],
            ],
            [
                'name' => 'Sushi Maré', 'handle' => 'sushi-mare', 'initials' => 'SM',
                'colors' => ['#1d4e89', '#0a1f3c'], 'tagline' => 'Peixe fresco todo dia', 'rating_base' => 5,
                'hours' => $nights('11:30', '15:00', [1, 2, 3, 4, 5, 6]),
                'photos' => ['Balcao de sushi', 'Combinado'],
                'posts' => [
                    ['hours_ago' => 9, 'aspect' => 'portrait', 'image_text' => 'Combinado 40 pecas', 'body' => 'Combinado de 40 peças pra dividir. Peixe chegou fresquinho hoje cedo 🐟'],
                    ['hours_ago' => 98, 'aspect' => 'story', 'image_text' => 'Festival de temaki', 'body' => 'Festival de temaki: qualquer sabor por R$ 24,90 até domingo!'],
                ],
            ],
            [
                'name' => 'Churrascaria Brasa Viva', 'handle' => 'brasa-viva', 'initials' => 'BV',
                'colors' => ['#d35400', '#4a1c03'], 'tagline' => 'Rodizio completo', 'rating_base' => 4,
                'hours' => [...$nights('11:30', '16:00', [1, 2, 3, 4, 5, 6, 0]), ...$nights('19:00', '23:00', [5, 6])],
                'photos' => ['Picanha na brasa', 'Buffet', 'Salao'],
                'posts' => [
                    ['hours_ago' => 14, 'aspect' => 'landscape', 'image_text' => 'Domingo em familia', 'body' => 'Domingo em família: criança até 10 anos não paga o rodízio 👨‍👩‍👧'],
                    ['hours_ago' => 60, 'body' => 'A picanha de hoje está especial. Só isso que a gente queria dizer. 🔥'],
                    ['hours_ago' => 200, 'aspect' => 'portrait', 'image_text' => 'Costela 12h no fogo', 'body' => 'Costela de chão, 12 horas no fogo. Sexta e sábado.'],
                ],
            ],
            [
                'name' => 'Grão & Prosa Café', 'handle' => 'grao-e-prosa', 'initials' => 'GP',
                'colors' => ['#8e6e53', '#3b2a1e'], 'tagline' => 'Cafe especial e bolos caseiros', 'rating_base' => 5,
                'hours' => $nights('08:00', '19:00', [1, 2, 3, 4, 5, 6]),
                'photos' => ['Espresso', 'Bolos do dia'],
                'posts' => [
                    ['hours_ago' => 1, 'aspect' => 'square', 'image_text' => 'Bolo de cenoura com brigadeiro', 'body' => 'Saiu agora: bolo de cenoura com cobertura de brigadeiro ☕🍫'],
                    ['hours_ago' => 26, 'body' => 'Novo grão na casa: Mantiqueira de Minas, notas de caramelo e frutas amarelas.'],
                    ['hours_ago' => 140, 'aspect' => 'story', 'image_text' => 'Cafe + pao de queijo R$ 12', 'body' => 'Combo café coado + pão de queijo por R$ 12 até as 10h.'],
                ],
            ],
            [
                'name' => 'Chapa Quente Burger', 'handle' => 'chapa-quente', 'initials' => 'CQ',
                'colors' => ['#e1a100', '#6b3a00'], 'tagline' => 'Smash burger na chapa', 'rating_base' => 4, 'reviews' => false,
                'hours' => $nights('18:00', '23:59', [3, 4, 5, 6, 0]),
                'photos' => ['Smash duplo'],
                'posts' => [
                    ['hours_ago' => 20, 'aspect' => 'portrait', 'image_text' => 'Inauguracao!', 'body' => 'INAUGURAMOS! 🎉 Primeira semana com batata frita grátis em todo combo.'],
                ],
            ],
            [
                // Hidden profile: must not appear in the feed or the search.
                'name' => 'Bar Escondido', 'handle' => 'bar-escondido', 'initials' => 'BE', 'public' => false,
                'colors' => ['#555555', '#222222'], 'tagline' => 'Perfil oculto', 'rating_base' => 3,
                'hours' => $nights('19:00', '02:00', [5, 6]),
                'photos' => [],
                'posts' => [
                    ['hours_ago' => 3, 'body' => 'Esta publicação não deve aparecer para os clientes (perfil oculto).'],
                ],
            ],
        ];
    }
}
