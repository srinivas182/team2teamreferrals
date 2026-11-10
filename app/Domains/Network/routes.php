<?php

use App\Domains\Identity\Http\Middleware\EnsureAccountIsActive;
use App\Domains\Identity\Http\Middleware\EnsureStaff;
use App\Domains\Network\Industries\Http\AdminAgreementController;
use App\Domains\Network\Industries\Http\AdminIndustriesController;
use App\Domains\Network\Industries\Http\AdminIndustryMessagesController;
use App\Domains\Network\Industries\Http\AdminIndustryTemplatesController;
use App\Domains\Network\Industries\Http\AdminLaunchController;
use App\Domains\Network\Locations\Http\AdminLocationsController;
use App\Domains\Network\Locations\Http\LocationLookupController;
use Illuminate\Support\Facades\Route;

Route::get('locations/cities', [LocationLookupController::class, 'cities'])->middleware('throttle:120,1')->name('locations.cities');

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:locations.manage'])
    ->prefix('admin/locations')->name('admin.locations.')->group(function (): void {
        Route::get('/', [AdminLocationsController::class, 'index'])->name('index');
        Route::patch('states/{state}', [AdminLocationsController::class, 'toggleState'])->name('states.toggle');
        Route::patch('cities/{city}', [AdminLocationsController::class, 'updateCity'])->name('cities.update');
        Route::post('cities/{city}/aliases', [AdminLocationsController::class, 'addAlias'])->name('cities.aliases.store');
        Route::delete('aliases/{alias}', [AdminLocationsController::class, 'removeAlias'])->name('aliases.destroy');
        Route::post('cities/{city}/merge', [AdminLocationsController::class, 'merge'])->name('cities.merge');
    });

Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:industries.manage'])
    ->prefix('admin/industries')->name('admin.industries.')->group(function (): void {
        Route::get('/', [AdminIndustriesController::class, 'index'])->name('index');
        Route::get('launch', AdminLaunchController::class)->name('launch');
        Route::get('counsel-pack/tier-{tier}.pdf', [AdminAgreementController::class, 'tierPack'])->whereIn('tier', ['a', 'b', 'c'])->name('counsel-pack.tier');
        Route::post('/', [AdminIndustriesController::class, 'store'])->name('store');
        Route::get('licensing.csv', [AdminIndustriesController::class, 'exportAllLicensing'])->name('licensing.export-all');
        Route::post('licensing/import', [AdminIndustriesController::class, 'importLicensing'])->name('licensing.import');
        Route::post('fees/unlock', [AdminIndustriesController::class, 'unlockFees'])->name('fees.unlock');
        Route::post('fees/lock', [AdminIndustriesController::class, 'lockFees'])->name('fees.lock');
        Route::get('{industry}', [AdminIndustriesController::class, 'show'])->name('show');
        Route::put('{industry}', [AdminIndustriesController::class, 'update'])->name('update');
        Route::put('{industry}/licensing/{state}', [AdminIndustriesController::class, 'saveLicensing'])->name('licensing.save');
        Route::get('{industry}/licensing.csv', [AdminIndustriesController::class, 'exportLicensing'])->name('licensing.export');
        Route::post('{industry}/approve', [AdminIndustriesController::class, 'approve'])->name('approve');
        Route::post('{industry}/revoke', [AdminIndustriesController::class, 'revoke'])->name('revoke');
        Route::post('{industry}/status', [AdminIndustriesController::class, 'status'])->name('status');
        Route::put('{industry}/fee', [AdminIndustriesController::class, 'saveFee'])->name('fee');
        Route::get('{industry}/messages', [AdminIndustryMessagesController::class, 'show'])->name('messages.show');
        Route::post('{industry}/messages', [AdminIndustryMessagesController::class, 'save'])->name('messages.save');
        Route::post('{industry}/messages/reset', [AdminIndustryMessagesController::class, 'reset'])->name('messages.reset');
        Route::post('{industry}/messages/{template}/activate', [AdminIndustryMessagesController::class, 'activate'])->name('messages.activate');
        Route::post('{industry}/client-texting', [App\Domains\Referrals\Http\GroupWordingController::class, 'texting'])->name('client-texting');
        Route::get('{industry}/training', [App\Domains\Network\Training\AdminTrainingController::class, 'show'])->name('training.show');
        Route::put('{industry}/training', [App\Domains\Network\Training\AdminTrainingController::class, 'save'])->name('training.save');
        Route::get('{industry}/agreement', [AdminAgreementController::class, 'show'])->name('agreement.show');
        Route::put('{industry}/agreement', [AdminAgreementController::class, 'save'])->name('agreement.save');
        Route::delete('{industry}/agreement', [AdminAgreementController::class, 'reset'])->name('agreement.reset');
        Route::post('{industry}/agreement/approve', [AdminAgreementController::class, 'approve'])->name('agreement.approve');
        Route::get('{industry}/agreement/preview.pdf', [AdminAgreementController::class, 'pdf'])->name('agreement.pdf');
        Route::get('{industry}/agreement/counsel-pack.pdf', [AdminAgreementController::class, 'counselPack'])->name('agreement.counsel-pack');
        Route::put('{industry}/agreement/addenda/{state}', [AdminAgreementController::class, 'saveAddendum'])->name('agreement.addendum');
        Route::get('{industry}/{kind}', [AdminIndustryTemplatesController::class, 'show'])->whereIn('kind', ['form', 'stages'])->name('templates.show');
        Route::put('{industry}/{kind}', [AdminIndustryTemplatesController::class, 'save'])->whereIn('kind', ['form', 'stages'])->name('templates.save');
        Route::delete('{industry}/{kind}', [AdminIndustryTemplatesController::class, 'reset'])->whereIn('kind', ['form', 'stages'])->name('templates.reset');
    });

// Admin → Groups.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:groups.manage'])
    ->prefix('admin/groups')->name('admin.groups.')->group(function (): void {
        Route::get('/', App\Domains\Network\Groups\Http\AdminGroupsController::class)->name('index');
        Route::get('overflow', [App\Domains\Network\Groups\Http\AdminNetworkGrowthController::class, 'overflow'])->name('overflow');
        Route::post('overflow', [App\Domains\Network\Groups\Http\AdminNetworkGrowthController::class, 'openNext'])->name('open-next');
        Route::post('private', [App\Domains\Network\Groups\Http\AdminNetworkGrowthController::class, 'createPrivate'])->name('private');
        Route::post('bulk-pricing', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'bulkPricing'])->name('bulk-pricing');
        Route::get('{group}/agreement', [AdminAgreementController::class, 'groupShow'])->name('agreement.show');
        Route::put('{group}/agreement', [AdminAgreementController::class, 'groupSave'])->name('agreement.save');
        Route::delete('{group}/agreement', [AdminAgreementController::class, 'groupReset'])->name('agreement.reset');
        Route::post('{group}/agreement/approve', [AdminAgreementController::class, 'groupApprove'])->name('agreement.approve');
        Route::get('{group}/{kind}', [AdminIndustryTemplatesController::class, 'groupShow'])->whereIn('kind', ['form', 'stages'])->name('templates.show');
        Route::put('{group}/{kind}', [AdminIndustryTemplatesController::class, 'groupSave'])->whereIn('kind', ['form', 'stages'])->name('templates.save');
        Route::delete('{group}/{kind}', [AdminIndustryTemplatesController::class, 'groupReset'])->whereIn('kind', ['form', 'stages'])->name('templates.reset');
        Route::get('{group}', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'show'])->name('show');
        Route::post('{group}', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'updateDetails'])->name('details');
        Route::put('{group}/{section}', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'updateSettings'])->whereIn('section', ['pricing', 'rules'])->name('settings');
        Route::delete('{group}/scheduled/{change}', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'cancelScheduled'])->whereNumber('change')->name('scheduled.cancel');
        Route::post('{group}/owners', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'addOwner'])->name('owners.add');
        Route::delete('{group}/owners/{user}', [App\Domains\Network\Groups\Http\AdminGroupsController::class, 'removeOwner'])->name('owners.remove');
    });

// Group owners: their own group's overview.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])
    ->get('app/group/{group}', App\Domains\Network\Groups\Http\OwnerGroupController::class)->name('app.group');

// Seats: members apply and answer offers; owners and staff decide applications.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::post('invites/{token}/accept', [App\Domains\Network\Seats\Http\SeatController::class, 'acceptInvitation'])->name('seats.invite.accept');
    Route::get('app/groups/{group}/apply', [App\Domains\Network\Seats\Http\SeatController::class, 'applyForm'])->name('seats.apply.form');
    Route::post('app/groups/{group}/apply', [App\Domains\Network\Seats\Http\SeatController::class, 'apply'])->middleware('throttle:20,60')->name('seats.apply');
    Route::get('app/seats/offers/{offer}', [App\Domains\Network\Seats\Http\SeatController::class, 'offer'])->whereNumber('offer')->name('seats.offer');
    Route::post('app/seats/offers/{offer}/accept', [App\Domains\Network\Seats\Http\SeatController::class, 'accept'])->whereNumber('offer')->name('seats.offer.accept');
    Route::post('app/seats/offers/{offer}/decline', [App\Domains\Network\Seats\Http\SeatController::class, 'decline'])->whereNumber('offer')->name('seats.offer.decline');
    foreach (['admin/groups' => 'admin', 'app/group' => 'owner'] as $prefix => $who) {
        Route::get("{$prefix}/{group}/applications", [App\Domains\Network\Seats\Http\SeatController::class, 'applications'])->name("seats.applications.{$who}");
        Route::post("{$prefix}/{group}/applications/{application}/approve", [App\Domains\Network\Seats\Http\SeatController::class, 'approve'])->whereNumber('application')->name("seats.approve.{$who}");
        Route::post("{$prefix}/{group}/applications/{application}/decline", [App\Domains\Network\Seats\Http\SeatController::class, 'declineApplication'])->whereNumber('application')->name("seats.decline.{$who}");
        Route::get("{$prefix}/{group}/seat-map", [App\Domains\Network\Seats\Http\SeatController::class, 'map'])->name("seats.map.{$who}");
        Route::post("{$prefix}/{group}/invites", [App\Domains\Network\Seats\Http\SeatController::class, 'invite'])->middleware('throttle:20,60')->name("seats.invite.{$who}");
    }
});

// Invitation landing page: public, so a new pro can see the offer before creating an account.
Route::middleware(['web', 'noindex:always'])->get('invites/{token}', [App\Domains\Network\Seats\Http\SeatController::class, 'invitation'])->name('seats.invite.show');

// Teams: profile, members and invitations.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/team', [App\Domains\Network\Teams\TeamController::class, 'show'])->name('team.show');
    Route::post('app/team', [App\Domains\Network\Teams\TeamController::class, 'update'])->name('team.update');
    Route::post('app/team/invites', [App\Domains\Network\Teams\TeamController::class, 'invite'])->middleware('throttle:20,60')->name('team.invite');
    Route::delete('app/team/invites/{invite}', [App\Domains\Network\Teams\TeamController::class, 'revokeInvite'])->whereNumber('invite')->name('team.invite.revoke');
    Route::delete('app/team/members/{member}', [App\Domains\Network\Teams\TeamController::class, 'removeMember'])->name('team.member.remove');
    Route::post('team-invites/{token}/accept', [App\Domains\Network\Teams\TeamController::class, 'accept'])->name('team.invite.accept');
});
Route::middleware(['web', 'noindex:always'])->get('team-invites/{token}', [App\Domains\Network\Teams\TeamController::class, 'invitation'])->name('team.invite.show');

// Licenses: teams add them; staff verify them.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/profile', [App\Domains\Network\Licenses\LicenseController::class, 'mine'])->name('licenses.mine');
    Route::post('app/licenses', [App\Domains\Network\Licenses\LicenseController::class, 'store'])->middleware('throttle:30,60')->name('licenses.store');
    Route::delete('app/licenses/{license}', [App\Domains\Network\Licenses\LicenseController::class, 'destroy'])->whereNumber('license')->name('licenses.destroy');
});
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified', EnsureStaff::class, 'can:licenses.verify'])->group(function (): void {
    Route::get('admin/licenses', [App\Domains\Network\Licenses\LicenseController::class, 'queue'])->name('admin.licenses');
    Route::post('admin/licenses/{license}', [App\Domains\Network\Licenses\LicenseController::class, 'decide'])->whereNumber('license')->name('admin.licenses.decide');
});

// Members: find a network; preview the team's public profile.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/groups', [App\Domains\Network\Onboarding\MemberGroupsController::class, 'index'])->name('member.groups');
    Route::get('app/team/preview', [App\Domains\Network\Onboarding\MemberGroupsController::class, 'preview'])->name('team.preview');
});

// Companies: dashboard, who pays, branch invitations.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/company', [App\Domains\Network\Companies\CompanyController::class, 'show'])->name('company.show');
    Route::post('app/company', [App\Domains\Network\Companies\CompanyController::class, 'store'])->name('company.store');
    Route::post('app/company/seats/{seat}/payer', [App\Domains\Network\Companies\CompanyController::class, 'payer'])->whereNumber('seat')->name('company.payer');
    Route::post('app/company/invites', [App\Domains\Network\Companies\CompanyController::class, 'invite'])->middleware('throttle:20,60')->name('company.invite');
    Route::post('company-invites/{token}/accept', [App\Domains\Network\Companies\CompanyController::class, 'accept'])->name('company.invite.accept');
});
Route::middleware(['web', 'noindex:always'])->get('company-invites/{token}', [App\Domains\Network\Companies\CompanyController::class, 'invitation'])->name('company.invite.show');

// Pre-launch "Reserve your city" (switch: Admin → System → Modules → Pre-launch reservations).
Route::middleware(['web', 'module:prelaunch'])->group(function (): void {
    Route::get('reserve', [App\Domains\Network\Prelaunch\ReserveController::class, 'page'])->name('reserve');
    Route::get('reserve/c/{shareId}/{size}.png', [App\Domains\Network\Prelaunch\ReserveController::class, 'image'])->where('shareId', '[0-9A-Za-z]{26}')->whereIn('size', ['og', 'square', 'story'])->middleware('throttle:120,1')->name('reserve.share.image');
    Route::get('reserve/c/{shareId}', [App\Domains\Network\Prelaunch\ReserveController::class, 'share'])->where('shareId', '[0-9A-Za-z]{26}')->name('reserve.share');
    Route::get('reserve/check', [App\Domains\Network\Prelaunch\ReserveController::class, 'check'])->middleware('throttle:60,1')->name('reserve.check');
    Route::post('reserve', [App\Domains\Network\Prelaunch\ReserveController::class, 'store'])->middleware('throttle:10,60')->name('reserve.store');
    Route::get('reserve/confirm/{token}', [App\Domains\Network\Prelaunch\ReserveController::class, 'confirm'])->middleware('noindex:always')->name('reserve.confirm');
    Route::post('reserve/claim/{token}', [App\Domains\Network\Prelaunch\ReserveController::class, 'claim'])->middleware(['auth', 'verified'])->name('reserve.claim');
    Route::get('reserve/claim/{token}', fn (string $token) => redirect('/reserve/confirm/'.$token))->middleware(['auth']);
});

// Training: lessons and quiz for members; the group switch for owners.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/training', [App\Domains\Network\Training\TrainingController::class, 'index'])->name('training.index');
    Route::get('app/training/{industry}', [App\Domains\Network\Training\TrainingController::class, 'show'])->name('training.show');
    Route::post('app/training/{industry}/lessons/{index}', [App\Domains\Network\Training\TrainingController::class, 'lesson'])->whereNumber('index')->name('training.lesson');
    Route::post('app/training/{industry}/quiz', [App\Domains\Network\Training\TrainingController::class, 'submit'])->middleware('throttle:20,60')->name('training.submit');
    Route::post('app/group/{group}/training', [App\Domains\Network\Training\TrainingController::class, 'ownerSwitch'])->name('training.switch');
    Route::get('app/training/{industry}/certificate.pdf', [App\Domains\Network\Training\TrainingController::class, 'certificate'])->name('training.certificate');
    Route::get('app/training/{industry}/refresher', [App\Domains\Network\Training\TrainingController::class, 'refresher'])->name('training.refresher');
    Route::post('app/team/members/{member}/remind-training', [App\Domains\Network\Training\TrainingController::class, 'remind'])->middleware('throttle:20,60')->name('training.remind');
});

// Referrals: send, inbox, sent, detail, accept and decline.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/send', [App\Domains\Referrals\Http\ReferralController::class, 'create'])->name('referrals.create');
    Route::get('app/send/preview', [App\Domains\Referrals\Http\ReferralController::class, 'preview'])->middleware('throttle:60,1')->name('referrals.preview');
    Route::post('app/send', [App\Domains\Referrals\Http\ReferralController::class, 'store'])->middleware('throttle:30,60')->name('referrals.store');
    Route::get('app/inbox', [App\Domains\Referrals\Http\ReferralController::class, 'inbox'])->name('referrals.inbox');
    Route::get('app/sent', [App\Domains\Referrals\Http\ReferralController::class, 'sent'])->name('referrals.sent');
    Route::get('app/referrals/{referral}', [App\Domains\Referrals\Http\ReferralController::class, 'show'])->name('referrals.show');
    Route::post('app/referrals/{referral}/accept', [App\Domains\Referrals\Http\ReferralController::class, 'accept'])->name('referrals.accept');
    Route::get('app/referrals/{referral}/files/{attachment}', [App\Domains\Referrals\Http\ReferralController::class, 'attachment'])->middleware('signed')->whereNumber('attachment')->name('referrals.attachment');
    Route::post('app/referrals/{referral}/mute', [App\Domains\Referrals\Http\ReferralController::class, 'mute'])->name('referrals.mute');
    Route::post('app/team/quick-replies', [App\Domains\Referrals\Http\ReferralController::class, 'quickReplies'])->name('team.quick-replies');
    Route::get('app/messages/search', [App\Domains\Referrals\Http\ReferralController::class, 'search'])->middleware('throttle:60,1')->name('messages.search');
    Route::post('app/referrals/{referral}/call', [App\Domains\Referrals\Http\ReferralController::class, 'logCall'])->name('referrals.call');
    Route::post('app/referrals/{referral}/stage', [App\Domains\Referrals\Http\ReferralController::class, 'stage'])->name('referrals.stage');
    Route::post('app/referrals/{referral}/note', [App\Domains\Referrals\Http\ReferralController::class, 'note'])->middleware('throttle:60,60')->name('referrals.note');
    Route::post('app/referrals/{referral}/nudge', [App\Domains\Referrals\Http\ReferralController::class, 'nudge'])->name('referrals.nudge');
    Route::post('app/referrals/{referral}/ask-client', [App\Domains\Referrals\Http\ReferralController::class, 'askClient'])->middleware('throttle:10,60')->name('referrals.ask-client');
    Route::post('app/referrals/{referral}/messages', [App\Domains\Referrals\Http\ReferralController::class, 'message'])->middleware('throttle:60,60')->name('referrals.message');
    Route::post('app/referrals/{referral}/sign', [App\Domains\Referrals\Http\ReferralController::class, 'sign'])->middleware('throttle:20,60')->name('referrals.sign');
    Route::get('app/referrals/{referral}/agreement.pdf', [App\Domains\Referrals\Http\ReferralController::class, 'agreementPdf'])->name('referrals.agreement.pdf');
    Route::post('app/referrals/{referral}/decline', [App\Domains\Referrals\Http\ReferralController::class, 'decline'])->name('referrals.decline');
});

// One-tap accept (signed, from the email or text) and the client's private status page.
Route::middleware(['web', 'noindex:always'])->group(function (): void {
    Route::get('q/{referral}/{team}', [App\Domains\Referrals\Http\ReferralController::class, 'quick'])->middleware('signed')->whereNumber('team')->name('referrals.quick');
    Route::post('q/{referral}/{team}', [App\Domains\Referrals\Http\ReferralController::class, 'quickAccept'])->middleware(['signed', 'throttle:20,1'])->whereNumber('team')->name('referrals.quick.accept');
    Route::get('c/{token}', [App\Domains\Referrals\Http\ReferralController::class, 'clientConsent'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('referrals.client-consent');
    Route::post('c/{token}', [App\Domains\Referrals\Http\ReferralController::class, 'clientConsentConfirm'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:20,1')->name('referrals.client-consent.confirm');
    Route::get('r/{token}', [App\Domains\Referrals\Http\ReferralController::class, 'client'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:60,1')->name('referrals.client');
});

// Group owners: their own wording for referral messages.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/group/{group}/messages', [App\Domains\Referrals\Http\GroupWordingController::class, 'show'])->name('group.wording');
    Route::post('app/group/{group}/messages', [App\Domains\Referrals\Http\GroupWordingController::class, 'save'])->name('group.wording.save');
    Route::delete('app/group/{group}/messages/{type}', [App\Domains\Referrals\Http\GroupWordingController::class, 'reset'])->name('group.wording.reset');
});

// Pipeline board, and the owners' pipeline editor (admins can lock it).
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/pipeline', [App\Domains\Referrals\Http\PipelineController::class, 'board'])->name('pipeline.board');
    Route::get('app/group/{group}/pipeline', [App\Domains\Referrals\Http\PipelineController::class, 'edit'])->name('group.pipeline');
    Route::put('app/group/{group}/pipeline', [App\Domains\Referrals\Http\PipelineController::class, 'save'])->name('group.pipeline.save');
    Route::post('app/group/{group}/pipeline/lock', [App\Domains\Referrals\Http\PipelineController::class, 'lock'])->name('group.pipeline.lock');
});

// Performance: service score, report cards, approved time off.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::get('app/performance', [App\Domains\Referrals\Http\PerformanceController::class, 'show'])->name('performance');
    Route::post('app/performance/time-off', [App\Domains\Referrals\Http\PerformanceController::class, 'addTimeOff'])->name('performance.time-off');
    Route::delete('app/performance/time-off/{id}', [App\Domains\Referrals\Http\PerformanceController::class, 'removeTimeOff'])->whereNumber('id')->name('performance.time-off.remove');
});

// Seat challenges: holders appeal and choose; group owners and admins decide; admins can cancel.
Route::middleware(['web', 'noindex:always', 'auth', EnsureAccountIsActive::class, 'verified'])->group(function (): void {
    Route::post('app/challenges/{id}/appeal', [App\Domains\Referrals\Http\ChallengeController::class, 'appeal'])->whereNumber('id')->name('challenges.appeal');
    Route::post('app/challenges/{id}/choice', [App\Domains\Referrals\Http\ChallengeController::class, 'choose'])->whereNumber('id')->name('challenges.choice');
    Route::get('app/group/{group}/challenges', [App\Domains\Referrals\Http\ChallengeController::class, 'index'])->name('group.challenges');
    Route::post('app/challenges/{id}/decide', [App\Domains\Referrals\Http\ChallengeController::class, 'decide'])->whereNumber('id')->name('challenges.decide');
    Route::post('app/challenges/{id}/cancel', [App\Domains\Referrals\Http\ChallengeController::class, 'cancel'])->whereNumber('id')->name('challenges.cancel');
});
