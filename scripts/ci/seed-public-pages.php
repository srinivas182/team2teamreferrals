<?php

/*
| CI only: one of every kind of public page, so the SEO/AEO audit and Lighthouse check real pages.
| Run: php artisan tinker --execute="require base_path('scripts/ci/seed-public-pages.php');"
*/

use App\Domains\Content\Industry\IndustryContent;
use App\Domains\Content\Posts;
use App\Domains\Identity\Models\User;
use App\Domains\Network\Groups\Models\Group;
use App\Domains\Network\Industries\Models\Industry;
use App\Domains\Network\Locations\Models\City;
use App\Domains\Network\Profiles\PublicProfiles;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Network\Seats\Seats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abort_if(app()->environment('production') && ! env('CI'), 403, 'CI only');
Artisan::call('t2t:install', ['--locations' => base_path('tests/fixtures/locations')]);
Artisan::call('t2t:locations:import-metros', ['file' => base_path('tests/fixtures/locations/cbsa.csv')]);
$staff = User::factory()->create(['email' => 'ci-staff@example.com']);

// Industry hubs (reviewed) and a live group with two public teams.
foreach (['solar', 'mortgage'] as $slug) {
    $i = Industry::where('slug', $slug)->firstOrFail();
    IndustryContent::save($i, IndustryContent::content($i), 'CI Reviewer', $staff);
    IndustryContent::publish($i, $staff);
}
$group = tap(Group::where('slug', 'solar-installers-network')->firstOrFail())->update(['status' => 'live']);
$seats = app(Seats::class);
foreach ([['Lone Star Solar', 'austin', 'https://lonestarsolar.example'], ['Hurricane Sun', 'houston', null]] as [$name, $city, $site]) {
    $team = tap(Team::forLeader(User::factory()->create()))->update(['name' => $name, 'website' => $site, 'bio' => "{$name} designs and installs rooftop solar for homes and small businesses, with in-house crews and a 25-year workmanship warranty."]);
    $seats->approve($seats->apply($group, $team, City::where('slug', $city)->firstOrFail(), 'L', null), null);
    PublicProfiles::ensureSlug($team);
    $team->forceFill(['profile_public' => true])->save();
}

// A published blog post (with its named reviewer) and a help article.
$help = DB::table('blog_categories')->where('slug', 'help-centre')->value('id');
$post = DB::table('posts')->where('category_id', '!=', $help)->orderBy('id')->first();
DB::table('posts')->where('id', $post->id)->update(['reviewer' => 'CI Reviewer']);
Posts::publish(DB::table('posts')->find($post->id), null, $staff);
Posts::publish(DB::table('posts')->where('category_id', $help)->orderBy('id')->first(), null, $staff);

// A reservation that opted in to the public wall.
DB::table('reservations')->insert(['share_id' => (string) Str::ulid(), 'group_id' => $group->id, 'city_id' => City::where('slug', 'fort-worth')->value('id'), 'email' => 'wall@example.com',
    'name' => 'Ana', 'business' => 'SunPath Solar', 'on_wall' => true, 'token_hash' => 'ci', 'status' => 'confirmed', 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
Artisan::call('cache:clear');
echo "Seeded public pages.\n";
