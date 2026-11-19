<?php

namespace App\Services;

use App\Models\ClientInquiry;
use App\Models\User;

class TutorialKnowledgeBase
{
    public function __construct(private readonly AiCredentialService $aiConfiguration) {}

    private const PAGE_TUTORIALS = [
        '/sales/dashboard' => 'sales-dashboard', '/sales' => 'sales-dashboard',
        '/sales/clients' => 'client-management', '/sales/inquiries' => 'client-inquiries',
        '/sales/calendar' => 'shipment-calendar',
        '/sales/availability' => 'shipment-availability', '/sales/forecasts/calendar' => 'shipment-forecasting',
        '/sales/forecasts/monthly' => 'shipment-forecasting', '/sales/forecasts/history' => 'shipment-forecasting',
        '/sales/cost-estimates' => 'internal-cost-estimates', '/sales/quotations' => 'quotation-preparation',
        '/sales/quotation-history' => 'quotation-preparation',
        '/admin/roles' => 'administrator-role-access',
    ];

    private const PAGE_PERMISSIONS = [
        '/sales/dashboard' => 'sales.dashboard.view', '/sales' => 'department.sales.view',
        '/sales/clients' => 'clients.view', '/sales/inquiries' => 'inquiries.view',
        '/sales/calendar' => 'sales.calendar.view',
        '/sales/availability' => 'sales.availability.view', '/sales/forecasts/calendar' => 'forecasts.view',
        '/sales/forecasts/monthly' => 'forecasts.view', '/sales/forecasts/history' => 'forecasts.view',
        '/sales/cost-estimates' => 'cost-estimates.view', '/sales/quotations' => 'quotations.view',
        '/sales/quotation-history' => 'quotations.history.view',
        '/admin/roles' => 'roles.view', '/admin/users' => 'users.view', '/admin/audit' => 'audit.view', '/profile' => 'profile.view',
        '/finance/approval-queue' => 'quotations.review', '/shipments' => 'shipments.view',
        '/accounting' => 'accounting.dashboard.view', '/executive' => 'executive.overview.view',
    ];

    /** @return array<string, mixed> */
    public function forUser(User $user, ?string $currentPath = null): array
    {
        if (! $this->aiConfiguration->tutorialsEnabled()) {
            return ['version' => 1, 'module' => 'sales', 'implemented_modules' => ['sales'], 'planned_modules' => ['operations', 'transport', 'forwarding', 'billing', 'accounting', 'finance', 'executive', 'administrator'], 'current_tutorial' => null, 'suggested_questions' => [], 'tutorials' => [], 'inquiries' => [], 'tutorials_enabled' => false];
        }
        $sales = require resource_path('ai-tutorials/v1/sales.php');
        $tutorials = collect($sales['tutorials'])
            ->filter(fn (array $tutorial): bool => $this->canUse($user, $tutorial['permissions'])
                && $this->authorizedPage($user, $tutorial['navigation_path']) !== null)
            ->map(function (array $tutorial, string $key) use ($user): array {
                $tutorial['related_pages'] = array_values(array_filter(
                    $tutorial['related_pages'], fn (string $path): bool => $this->authorizedPage($user, $path) !== null
                ));
                $tutorial['required_role'] = match ($key) {
                    'internal-cost-estimates' => 'Accounting reviewer for review actions; Sales owner prepares and submits estimates.',
                    'quotation-approval' => 'Accounting, Finance or Executive for matching review stage; final approver needs quotations.approve.',
                    'administrator-role-access' => 'Administrator or another account with the role/permission management authorization required for the action.',
                    default => 'Any active account whose effective permissions below authorize the action; role names alone do not grant access.',
                };

                return ['key' => $key, ...$tutorial];
            })
            ->values();

        $currentTutorial = self::PAGE_TUTORIALS[$currentPath ?? ''] ?? null;
        $suggestions = match ($currentTutorial) {
            'client-management' => ['How do I add a client?', 'How do I submit an inquiry?'],
            'client-inquiries' => ['How do I submit an inquiry?', 'What happens after submission?', 'What should I do next?'],
            'shipment-forecasting' => ['How do I create a forecast?', 'Can I use a forecast as a shipment?'],
            'internal-cost-estimates' => ['Who reviews cost estimates?', 'Why can’t I create a quotation?'],
            'quotation-preparation', 'client-quotation-acceptance' => ['How do I create a quotation?', 'Who needs to approve this?'],
            'shipment-calendar' => ['How do I check shipment schedules?', 'Are these confirmed shipments?'],
            'shipment-availability' => ['How do I check shipment availability?', 'May available shipment ba next week?'],
            'administrator-role-access' => ['How do I review a role?', 'Why can’t I change a protected permission?'],
            default => ['How do I use this page?', 'How do I use Sales?', 'Paano gumawa ng inquiry?'],
        };

        $records = [];
        if ($user->can('inquiries.view')) {
            $records = ClientInquiry::query()->where('sales_owner_id', $user->id)
                ->latest('updated_at')->limit(100)->get(['id', 'reference', 'status'])
                ->map(fn (ClientInquiry $inquiry): array => ['id' => $inquiry->id, 'reference' => $inquiry->reference, 'status' => $inquiry->status])->all();
        }

        return [
            'version' => $sales['version'],
            'module' => 'sales',
            'implemented_modules' => ['sales'],
            'planned_modules' => ['operations', 'transport', 'forwarding', 'billing', 'accounting', 'finance', 'executive', 'administrator'],
            'current_tutorial' => $currentTutorial && $tutorials->contains('key', $currentTutorial) ? $currentTutorial : null,
            'suggested_questions' => $suggestions,
            'tutorials' => $tutorials,
            'inquiries' => $records,
            'tutorials_enabled' => true,
        ];
    }

    /** @return array<string, string>|null */
    public function authorizedPage(User $user, string $path): ?array
    {
        $permission = self::PAGE_PERMISSIONS[$path] ?? null;
        if (! $permission || ! $user->can($permission)) {
            return null;
        }

        $labels = [
            '/sales/dashboard' => 'Sales Dashboard', '/sales' => 'Sales Overview', '/sales/clients' => 'Client Management',
            '/sales/inquiries' => 'Client Inquiries',
            '/sales/calendar' => 'Shipment Calendar', '/sales/availability' => 'Shipment Availability',
            '/sales/forecasts/calendar' => 'Forecast Calendar', '/sales/forecasts/monthly' => 'Monthly Demand',
            '/sales/forecasts/history' => 'Forecast History', '/sales/cost-estimates' => 'Cost Estimates',
            '/sales/quotations' => 'Quotations', '/sales/quotation-history' => 'Quotation History',
            '/admin/roles' => 'Roles & Permissions', '/admin/users' => 'User Management', '/profile' => 'My Profile',
            '/admin/audit' => 'Audit Logs', '/finance/approval-queue' => 'Quotation Approval Queue',
            '/shipments' => 'Shipment Register', '/accounting' => 'Accounting Overview', '/executive' => 'Executive Overview',
        ];

        return ['path' => $path, 'label' => $labels[$path]];
    }

    /** @param list<string> $permissions */
    private function canUse(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
