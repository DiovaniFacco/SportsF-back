<?php

namespace Database\Seeders;

use App\Models\Amenity;
use App\Models\Sport;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Usuário administrador essencial (acesso inicial ao sistema)
        User::firstOrCreate(
            ['email' => 'admin@sfacco.com'],
            [
                'name' => 'Administrador Sfacco',
                'password' => Hash::make('password123'),
                'role' => User::ROLE_ADMIN,
                'phone' => '11999999999',
            ]
        );

        // 2. Esportes essenciais (usados nos filtros e cadastro de quadras)
        $sportsData = [
            ['name' => 'Vôlei', 'slug' => 'volei', 'icon' => 'VolleyballIcon'],
            ['name' => 'Futevôlei', 'slug' => 'futevolei', 'icon' => 'FutevoleiIcon'],
            ['name' => 'Beach Tennis', 'slug' => 'beach-tennis', 'icon' => 'BeachTennisIcon'],
            ['name' => 'Padel', 'slug' => 'padel', 'icon' => 'PadelIcon'],
        ];

        foreach ($sportsData as $data) {
            Sport::firstOrCreate(
                ['slug' => $data['slug']],
                ['name' => $data['name'], 'icon' => $data['icon']]
            );
        }

        // 3. Comodidades essenciais (usadas no cadastro de complexos)
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

        foreach ($amenitiesData as $aData) {
            Amenity::firstOrCreate(
                ['name' => $aData['name']],
                ['icon' => $aData['icon']]
            );
        }

        // REMOVIDO de propósito: seed de venues/quadras genéricas,
        // operating hours e reviews demo. Esses dados apagavam/voltavam
        // por cima dos dados reais do usuário a cada deploy (start.sh
        // rodava `db:seed`). Complexos e quadras agora são criados
        // apenas via API/painel, sem repopulação automática.
    }
}
