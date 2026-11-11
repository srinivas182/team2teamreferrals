<?php

use App\Domains\Identity\Models\User;
use App\Domains\Network\Seats\Models\Team;
use App\Domains\Platform\Ai\AiCredits;
use App\Domains\Platform\Ai\AiGateway;
use App\Domains\Platform\Ai\Prompts;
use App\Domains\Platform\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $s = app(Settings::class);
    $s->set('ai.driver', 'anthropic');
    $s->set('ai.anthropic.api_key', 'k', secret: true);
    foreach (['model' => 'claude-test', 'input_price' => '1', 'output_price' => '5', 'monthly_cap' => '100'] as $k => $v) {
        $s->set("ai.anthropic.{$k}", $v);
    }
    $this->sent = [];
    Http::fake(['api.anthropic.com/*' => function ($req) {
        $this->sent[] = $req->data();

        return Http::response(['content' => [['type' => 'text', 'text' => 'A short summary.']], 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]]);
    }]);
    $this->admin = User::factory()->create(['two_factor_secret' => encrypt('S'), 'two_factor_confirmed_at' => now()]);
    $this->admin->assignRole('super_admin');
    $this->team = tap(Team::forLeader(User::factory()->create()))->update(['name' => 'Hurricane Sun']);
    $this->run = fn () => app(AiGateway::class)->run($this->team, $this->team->leader, 'lead_summary', 'lead_summary', ['referral' => 'Industry: Solar', 'fee_rule' => '']);
});

it('[2] saves edits as new versions, goes live only when activated, and rolls back', function () {
    $d = Prompts::DEFAULTS['lead_summary'];
    $this->actingAs($this->team->leader)->post('/admin/ai/prompts/lead_summary', ['system' => $d['system'], 'template' => $d['template'], 'note' => 'x'])->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/ai')->assertInertia(fn ($p) => $p->component('admin/Ai')->where('prompts.0.versions.0.version', 1)->where('prompts.0.versions.0.active', true));
    $this->post('/admin/ai/prompts/lead_summary', ['system' => $d['system'], 'template' => $d['template'].' Mention the timeline first. VERSION-TWO', 'note' => 'Timeline first'])->assertSessionHas('status', 'ai-prompt-saved');
    expect(Prompts::active('lead_summary')['version'])->toBe(1); // saved, not live yet
    ($this->run)();
    expect($this->sent[0]['messages'][0]['content'])->not->toContain('VERSION-TWO');
    $this->post('/admin/ai/prompts/lead_summary/2/activate')->assertSessionHas('status', 'ai-prompt-live');
    ($this->run)();
    expect($this->sent[1]['messages'][0]['content'])->toContain('VERSION-TWO');
    $this->post('/admin/ai/prompts/lead_summary/1/activate'); // roll back
    expect(Prompts::active('lead_summary')['version'])->toBe(1)->and(DB::table('ai_prompts')->where('active', true)->count())->toBe(1);
});

it('[2] refuses edits that drop the data placeholders or the data-not-instructions rule', function () {
    $d = Prompts::DEFAULTS['lead_summary'];
    $this->actingAs($this->admin)->post('/admin/ai/prompts/lead_summary', ['system' => $d['system'], 'template' => 'Summarise it.', 'note' => 'x'])->assertSessionHasErrors('template');
    $this->post('/admin/ai/prompts/lead_summary', ['system' => 'Summarise. {fee_rule}', 'template' => $d['template'], 'note' => 'x'])->assertSessionHasErrors('system');
    $this->post('/admin/ai/prompts/unknown', ['system' => 'a', 'template' => 'b', 'note' => 'c'])->assertNotFound();
    expect(DB::table('ai_prompts')->where('version', '>', 1)->count())->toBe(0)->and(Prompts::active('lead_summary')['version'])->toBe(1);
});

it('[2] test-runs a version on invented sample data, never member data', function () {
    $this->actingAs($this->admin)->post('/admin/ai/prompts/lead_summary/1/test')->assertSessionHas('ai_test', fn ($r) => $r['text'] === 'A short summary.' && str_contains($r['meta'], 'anthropic'));
    expect($this->sent[0]['messages'][0]['content'])->toContain('Sam P.')->and(DB::table('ai_usage_logs')->count())->toBe(0); // not charged to any team
});

it('[5] reports usage and cost by feature, model and team, with blocked reasons and feedback per version', function () {
    ($this->run)();
    $id = (int) DB::table('ai_usage_logs')->value('id');
    DB::table('ai_feedback')->insert(['usage_id' => $id, 'user_id' => $this->team->leader_id, 'rating' => 1, 'created_at' => now()]);
    app(Settings::class)->set('ai.credits.daily_cap', '1');
    ($this->run)(); // blocked by the daily cap
    $this->actingAs($this->admin)->get('/admin/ai')->assertInertia(fn ($p) => $p->where('totals.calls', 1)->where('totals.blocked', 1)->where('totals.cost', fn ($c) => (float) $c === 0.0)
        ->where('byFeature.0.feature', 'lead_summary')->where('byModel.0.model', 'claude-test')->where('byTeam.0.team', 'Hurricane Sun')->where('byTeam.0.credits', 1)
        ->where('problems.0.reason', 'Daily cap reached')->where('prompts.0.versions.0.up', 1));
    $this->get('/admin/ai?month='.now()->subMonth()->format('Y-m'))->assertInertia(fn ($p) => $p->where('totals.calls', 0));
});

it('changes the credit defaults, which apply to the next allowance', function () {
    $this->actingAs($this->admin)->post('/admin/ai/credits', ['per_seat' => 250, 'per_seat_premium' => 600, 'daily_cap' => 50])->assertSessionHas('status', 'ai-credits-saved');
    AiCredits::grantMonthly($this->team);
    expect(AiCredits::balance($this->team))->toBe(250)->and(AiCredits::setting('daily_cap'))->toBe(50);
    $this->post('/admin/ai/credits', ['per_seat' => -1, 'per_seat_premium' => 600, 'daily_cap' => 0])->assertSessionHasErrors(['per_seat', 'daily_cap']);
});

it('[2] keeps the default as version 1 even when the first action is an edit', function () {
    $d = Prompts::DEFAULTS['lead_summary'];
    $this->actingAs($this->admin)->post('/admin/ai/prompts/lead_summary', ['system' => $d['system'], 'template' => $d['template'].' Be brief.', 'note' => 'Shorter']);
    expect(DB::table('ai_prompts')->orderBy('version')->pluck('note', 'version')->all())->toBe([1 => 'Default', 2 => 'Shorter']);
});
