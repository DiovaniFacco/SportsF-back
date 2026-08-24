<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\Sport;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueReview;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Criar Usuários
        $admin = User::firstOrCreate(
            ['email' => 'admin@sfacco.com'],
            [
                'name' => 'Administrador Sfacco',
                'password' => Hash::make('password123'),
                'role' => User::ROLE_ADMIN,
                'phone' => '11999999999',
            ]
        );

        $gestor = User::firstOrCreate(
            ['email' => 'gestor@arena.com'],
            [
                'name' => 'Carlos Silva (Gestor Arena)',
                'password' => Hash::make('password123'),
                'role' => User::ROLE_GESTOR,
                'phone' => '11988887777',
            ]
        );

        $cliente1 = User::firstOrCreate(
            ['email' => 'cliente@gmail.com'],
            [
                'name' => 'Lucas Ferreira',
                'password' => Hash::make('password123'),
                'role' => User::ROLE_CLIENTE,
                'phone' => '11977776666',
            ]
        );

        $cliente2 = User::firstOrCreate(
            ['email' => 'mariana@gmail.com'],
            [
                'name' => 'Mariana Souza',
                'password' => Hash::make('password123'),
                'role' => User::ROLE_CLIENTE,
                'phone' => '11966665555',
            ]
        );

        // 2. Criar Esportes (Sports) - Apenas Vôlei, Futevôlei, Beach Tennis e Padel
        $sportsData = [
            ['name' => 'Vôlei', 'slug' => 'volei', 'icon' => 'VolleyballIcon'],
            ['name' => 'Futevôlei', 'slug' => 'futevolei', 'icon' => 'FutevoleiIcon'],
            ['name' => 'Beach Tennis', 'slug' => 'beach-tennis', 'icon' => 'BeachTennisIcon'],
            ['name' => 'Padel', 'slug' => 'padel', 'icon' => 'PadelIcon'],
        ];

        $sportsMap = [];
        foreach ($sportsData as $data) {
            $sportsMap[$data['name']] = Sport::firstOrCreate(
                ['slug' => $data['slug']],
                ['name' => $data['name'], 'icon' => $data['icon']]
            );
        }

        // 3. Criar Comodidades (Amenities)
        $amenitiesData = [
            ['name' => 'Vestiário Climatizado', 'icon' => 'shower'],
            ['name' => 'Estacionamento com Valet', 'icon' => 'car'],
            ['name' => 'Iluminação LED Pro', 'icon' => 'sun'],
            ['name' => 'Lanchonete Gourmet', 'icon' => 'utensils'],
            ['name' => 'Quadra Coberta', 'icon' => 'shield'],
            ['name' => 'WiFi Grátis', 'icon' => 'wifi'],
            ['name' => 'Bar Sunset', 'icon' => 'beer'],
            ['name' => 'Loja Pro Shop', 'icon' => 'shopping-bag'],
        ];

        $amenitiesMap = [];
        foreach ($amenitiesData as $aData) {
            $amenitiesMap[$aData['name']] = Amenity::firstOrCreate(
                ['name' => $aData['name']],
                ['icon' => $aData['icon']]
            );
        }

        // 4. Criar Complexo 1: Arena Central Esportes (São Paulo, SP)
        $venue1 = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Central Esportes',
            'description' => 'Complexo esportivo completo com quadras de Vôlei de Areia, Futevôlei, Beach Tennis e Padel, vestiários climatizados e lanchonete gourmet com área de convivência.',
            'address' => 'Av. Paulista',
            'number' => '1500',
            'neighborhood' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01310-200',
            'latitude' => -23.56141400,
            'longitude' => -46.65588100,
            'phone' => '(11) 98888-8888',
            'cover_image_url' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018?auto=format&fit=crop&w=800&q=80',
        ]);

        $venue1->amenities()->attach([
            $amenitiesMap['Vestiário Climatizado']->id,
            $amenitiesMap['Estacionamento com Valet']->id,
            $amenitiesMap['Iluminação LED Pro']->id,
            $amenitiesMap['Lanchonete Gourmet']->id,
            $amenitiesMap['Quadra Coberta']->id,
        ]);

        // Quadras da Arena Central
        $court1_1 = Court::create([
            'venue_id' => $venue1->id,
            'sport_id' => $sportsMap['Vôlei']->id,
            'name' => 'Arena Vôlei 1 — Areia Quartzosa',
            'type' => 'Vôlei',
            'hourly_rate' => 140.00,
            'surface' => 'Areia Quartzosa Filtrada',
            'capacity' => '4 a 12 Jogadores',
            'is_covered' => false,
        ]);
        $court1_1->sports()->attach([$sportsMap['Vôlei']->id]);

        $court1_2 = Court::create([
            'venue_id' => $venue1->id,
            'sport_id' => $sportsMap['Futevôlei']->id,
            'name' => 'Arena Futevôlei 2 — Coberta Pro',
            'type' => 'Futevôlei',
            'hourly_rate' => 150.00,
            'surface' => 'Areia Fina Climatizada',
            'capacity' => '2 a 6 Jogadores',
            'is_covered' => true,
        ]);
        $court1_2->sports()->attach([$sportsMap['Futevôlei']->id]);

        $court1_3 = Court::create([
            'venue_id' => $venue1->id,
            'sport_id' => $sportsMap['Beach Tennis']->id,
            'name' => 'Arena Areia 3 — Beach Tennis & Vôlei',
            'type' => 'Beach Tennis, Vôlei',
            'hourly_rate' => 130.00,
            'surface' => 'Areia Quartzosa Filtrada',
            'capacity' => '2 a 6 Jogadores',
            'is_covered' => false,
        ]);
        $court1_3->sports()->attach([$sportsMap['Beach Tennis']->id, $sportsMap['Vôlei']->id]);

        $court1_4 = Court::create([
            'venue_id' => $venue1->id,
            'sport_id' => $sportsMap['Padel']->id,
            'name' => 'Quadra Padel Pro — Vidro 10mm',
            'type' => 'Padel',
            'hourly_rate' => 180.00,
            'surface' => 'Grama Sintética Azul Pro',
            'capacity' => '4 Jogadores',
            'is_covered' => true,
        ]);
        $court1_4->sports()->attach([$sportsMap['Padel']->id]);

        // 5. Criar Complexo 2: Metropolitan Racket Club (São Paulo, SP)
        $venue2 = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Metropolitan Arena & Racket Club',
            'description' => 'Centro de alta performance especializado em Padel, Beach Tennis, Futevôlei e Vôlei de Areia com treinadores certificados.',
            'address' => 'Rua das Palmeiras',
            'number' => '450',
            'neighborhood' => 'Jardins',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip_code' => '01423-010',
            'latitude' => -23.57022000,
            'longitude' => -46.66512000,
            'phone' => '(11) 97777-6666',
            'cover_image_url' => 'https://images.unsplash.com/photo-1622279457486-62dcc4a431d6?auto=format&fit=crop&w=800&q=80',
        ]);

        $venue2->amenities()->attach([
            $amenitiesMap['Vestiário Climatizado']->id,
            $amenitiesMap['Estacionamento com Valet']->id,
            $amenitiesMap['Loja Pro Shop']->id,
            $amenitiesMap['Bar Sunset']->id,
        ]);

        // Quadra Multi-Esporte 1 (Beach Tennis & Padel)
        $court2_1 = Court::create([
            'venue_id' => $venue2->id,
            'sport_id' => $sportsMap['Beach Tennis']->id,
            'name' => 'Quadra 1 — Beach Tennis & Padel Pro',
            'type' => 'Beach Tennis, Padel',
            'hourly_rate' => 180.00,
            'surface' => 'Areia Quartzosa / Vidro Panorama',
            'capacity' => '2 a 4 Jogadores',
            'is_covered' => true,
        ]);
        $court2_1->sports()->attach([$sportsMap['Beach Tennis']->id, $sportsMap['Padel']->id]);

        $court2_2 = Court::create([
            'venue_id' => $venue2->id,
            'sport_id' => $sportsMap['Padel']->id,
            'name' => 'Quadra de Padel 2 — Vidro Panorama',
            'type' => 'Padel',
            'hourly_rate' => 200.00,
            'surface' => 'Grama Sintética Azul Pro',
            'capacity' => '4 Jogadores',
            'is_covered' => true,
        ]);
        $court2_2->sports()->attach([$sportsMap['Padel']->id]);

        // 6. Criar Horários de Funcionamento para todas as quadras (Seg-Dom 08:00 às 23:00)
        $courts = Court::all();
        foreach ($courts as $court) {
            for ($day = 0; $day <= 6; $day++) {
                OperatingHour::create([
                    'court_id' => $court->id,
                    'day_of_week' => $day,
                    'start_time' => '08:00:00',
                    'end_time' => '23:00:00',
                    'slot_duration_minutes' => 60,
                ]);
            }
        }

        // 7. Criar Avaliações Iniciais
        VenueReview::create([
            'venue_id' => $venue1->id,
            'user_id' => $cliente1->id,
            'rating' => 5,
            'comment' => 'Estrutura impecável! A areia é excelente e os vestiários são muito limpos.',
        ]);

        VenueReview::create([
            'venue_id' => $venue1->id,
            'user_id' => $cliente2->id,
            'rating' => 5,
            'comment' => 'Ótimo atendimento e a lanchonete é muito boa.',
        ]);

        VenueReview::create([
            'venue_id' => $venue2->id,
            'user_id' => $cliente1->id,
            'rating' => 5,
            'comment' => 'As quadras de beach tennis e padel são de nível profissional. Recomendo muito!',
        ]);

        VenueReview::create([
            'venue_id' => $venue2->id,
            'user_id' => $cliente2->id,
            'rating' => 4,
            'comment' => 'Muito bom centro de padel e futevôlei.',
        ]);
    }
}
