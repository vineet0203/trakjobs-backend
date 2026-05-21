<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Quote;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendorId = $user?->vendor_id ?? $user?->id;
        if (!$vendorId) {
            return response()->json(['message' => 'User not associated with a vendor.'], 403);
        }
        [$from, $to] = $this->resolveDateRange($request);
        return response()->json(['data' => [
            'stats'            => $this->getStats($vendorId, $from, $to),
            'recentBookings'   => $this->getRecentBookings($vendorId),
            'upcomingBookings' => $this->getUpcomingBookings($vendorId),
            'teamStatus'       => $this->getTeamStatus($vendorId, $from, $to),
            'totalEarning'   => $this->getTotalEarning($vendorId, $from, $to),
            'earningChart'   => $this->getEarningChartData($vendorId, $from, $to),
            'recentQuotes'   => $this->getRecentQuotes($vendorId, $from, $to),
            'recentInvoices' => $this->getRecentInvoices($vendorId, $from, $to),
            'bookingStatus'  => [
                'completed' => Job::where('vendor_id', $vendorId)->whereBetween('created_at', [$from, $to])->whereIn('status', ['completed', 'finished'])->count(),
                'upcoming'  => Job::where('vendor_id', $vendorId)->whereBetween('created_at', [$from, $to])->whereIn('status', ['in_progress', 'assigned', 'scheduled', 'pending'])->count(),
                'cancelled' => Job::where('vendor_id', $vendorId)->whereBetween('created_at', [$from, $to])->whereIn('status', ['cancelled', 'rejected'])->count(),
            ],
            'is_available'   => \App\Models\Vendor::find($vendorId)?->status === 'active'
        ]]);
    }

    public function toggleAvailability(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendorId = $user?->vendor_id ?? $user?->id;
        $vendor = \App\Models\Vendor::find($vendorId);
        
        if (!$vendor) {
            return response()->json(['message' => 'Vendor not found.'], 404);
        }

        $validated = $request->validate([
            'is_available' => 'required|boolean'
        ]);

        $vendor->status = $validated['is_available'] ? 'active' : 'inactive';
        $vendor->save();

        return response()->json([
            'message' => 'Availability updated successfully.',
            'is_available' => $vendor->status === 'active'
        ]);
    }

    private function getStats(int $vendorId, Carbon $from, Carbon $to): array
    {
        [$pf, $pt] = $this->getPreviousRange($from, $to);
        $eids = Employee::where('vendor_id', $vendorId)->pluck('id')->toArray();

        $aj  = Job::where('vendor_id', $vendorId)->whereBetween('updated_at', [$from, $to])->whereIn('status', ['in_progress','assigned','scheduled'])->count();
        $paj = Job::where('vendor_id', $vendorId)->whereBetween('updated_at', [$pf, $pt])->whereIn('status', ['in_progress','assigned','scheduled'])->count();
        $tq  = Quote::where('vendor_id', $vendorId)->whereBetween('created_at', [$from, $to])->count();
        $ptq = Quote::where('vendor_id', $vendorId)->whereBetween('created_at', [$pf, $pt])->count();
        $tj  = Job::where('vendor_id', $vendorId)->whereBetween('created_at', [$from, $to])->count();
        $ptj = Job::where('vendor_id', $vendorId)->whereBetween('created_at', [$pf, $pt])->count();
        $ti  = empty($eids) ? 0 : Invoice::whereIn('employee_id', $eids)->whereBetween('created_at', [$from, $to])->count();
        $pti = empty($eids) ? 0 : Invoice::whereIn('employee_id', $eids)->whereBetween('created_at', [$pf, $pt])->count();
        $tb  = Booking::where('vendor_id', $vendorId)->whereBetween('created_at', [$from, $to])->count();
        $ptb = Booking::where('vendor_id', $vendorId)->whereBetween('created_at', [$pf, $pt])->count();

        return [
            ['label'=>'Active Job',    'value'=>$aj,  'change'=>$this->calcChange($aj,  $paj), 'changeLabel'=>'vs yesterday',    'color'=>'green',  'icon'=>'briefcase'],
            ['label'=>'Total Quote',   'value'=>$tq,  'change'=>$this->calcChange($tq,  $ptq), 'changeLabel'=>'vs last 7 days', 'color'=>'blue',   'icon'=>'file'],
            ['label'=>'Total Job',     'value'=>$tj,  'change'=>$this->calcChange($tj,  $ptj), 'changeLabel'=>'vs last 7 days', 'color'=>'purple', 'icon'=>'tool'],
            ['label'=>'Total Invoice', 'value'=>$ti,  'change'=>$this->calcChange($ti,  $pti), 'changeLabel'=>'vs last 7 days', 'color'=>'orange', 'icon'=>'receipt'],
            ['label'=>'Total Booking', 'value'=>$tb,  'change'=>$this->calcChange($tb,  $ptb), 'changeLabel'=>'vs last 7 days', 'color'=>'cyan',   'icon'=>'calendar'],
        ];
    }

    private function getRecentBookings(int $vendorId): array
    {
        return Schedule::where('vendor_id', $vendorId)
            ->with(['job.client', 'job.customer'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($s) {
                $job = $s->job;
                $client = $job?->customer?->name ?? $job?->client?->full_name ?? $job?->client?->name ?? 'N/A';
                return [
                    'id'      => $s->id,
                    'job_number' => $job?->job_number ?? strval($s->id),
                    'time'    => Carbon::parse($s->start_datetime)->format('h:i A'),
                    'date'    => Carbon::parse($s->start_datetime)->format('M d, Y'),
                    'client'  => $client,
                    'service' => $job?->title ?? $job?->service_type ?? 'Service',
                    'address' => $job?->address ?? $s->location ?? '',
                    'status'  => $s->status ?? 'scheduled',
                ];
            })->toArray();
    }

    private function getUpcomingBookings(int $vendorId): array
    {
        return Schedule::where('vendor_id', $vendorId)
            ->where('start_datetime', '>=', Carbon::now())
            ->whereIn('status', ['scheduled', 'pending', 'approved', 'in_progress'])
            ->with(['job.client', 'job.customer'])
            ->orderBy('start_datetime', 'asc')
            ->limit(5)
            ->get()
            ->map(function ($s) {
                $job = $s->job;
                $client = $job?->customer?->name ?? $job?->client?->full_name ?? $job?->client?->name ?? 'N/A';
                return [
                    'id'      => $s->id,
                    'job_number' => $job?->job_number ?? strval($s->id),
                    'time'    => Carbon::parse($s->start_datetime)->format('h:i A'),
                    'date'    => Carbon::parse($s->start_datetime)->format('M d, Y'),
                    'client'  => $client,
                    'service' => $job?->title ?? $job?->service_type ?? 'Service',
                    'address' => $job?->address ?? $s->location ?? '',
                    'status'  => $s->status ?? 'scheduled',
                ];
            })->toArray();
    }

        private function getTeamStatus(int $vendorId, Carbon $from, Carbon $to): array
    {
        $now = Carbon::now();
        $soon = $now->copy()->addMinutes(30);

        $employees = Employee::where('vendor_id', $vendorId)
            ->where('is_active', true)
            ->get(['id', 'name', 'first_name', 'last_name', 'role', 'profile_photo_path']);

        $total = max($employees->count(), 1);
        $eids  = $employees->pluck('id')->toArray();

        // Active schedules RIGHT NOW
        $activeSchedules = Schedule::where('vendor_id', $vendorId)
            ->where('start_datetime', '<=', $now)
            ->where('end_datetime', '>=', $now)
            ->with(['job', 'jobAssignments'])
            ->get();

        // On Route - starts within 30 mins
        $onRouteSchedules = Schedule::where('vendor_id', $vendorId)
            ->where('start_datetime', '>', $now)
            ->where('start_datetime', '<=', $soon)
            ->with(['job', 'jobAssignments'])
            ->get();

        // Clocked in employees
        $clockedInIds = DB::table('time_entries')
            ->whereIn('employee_id', $eids)
            ->whereNull('check_out')
            ->whereDate('check_in', $now->toDateString())
            ->pluck('employee_id')
            ->toArray();

        // Map employee_id to schedule
        $onJobIds = $activeSchedules->flatMap(fn($s) => $s->jobAssignments->pluck('employee_id'))->unique()->toArray();
        $onRouteIds = $onRouteSchedules->flatMap(fn($s) => $s->jobAssignments->pluck('employee_id'))->unique()->toArray();

        $scheduleByEmployee = [];
        foreach ($activeSchedules as $s) {
            foreach ($s->jobAssignments as $ja) {
                $scheduleByEmployee[$ja->employee_id] = $s;
            }
        }
        $routeByEmployee = [];
        foreach ($onRouteSchedules as $s) {
            foreach ($s->jobAssignments as $ja) {
                $routeByEmployee[$ja->employee_id] = $s;
            }
        }

        $members = $employees->map(function ($emp) use ($onJobIds, $onRouteIds, $clockedInIds, $scheduleByEmployee, $routeByEmployee) {
            $name = trim(($emp->first_name ?? '') . ' ' . ($emp->last_name ?? '')) ?: ($emp->name ?? 'Employee');

            if (in_array($emp->id, $onJobIds)) {
                $s = $scheduleByEmployee[$emp->id] ?? null;
                $status = 'on_job';
                $task = $s?->job?->title ?? 'Active Job';
                $time = $s ? Carbon::parse($s->start_datetime)->format('h:i A') . ' - ' . Carbon::parse($s->end_datetime)->format('h:i A') : null;
            } elseif (in_array($emp->id, $onRouteIds)) {
                $s = $routeByEmployee[$emp->id] ?? null;
                $status = 'on_route';
                $task = $s?->job?->title ?? 'Heading to job';
                $time = $s ? 'Starts ' . Carbon::parse($s->start_datetime)->format('h:i A') : null;
            } elseif (in_array($emp->id, $clockedInIds)) {
                $status = 'clocked_in';
                $task = 'Clocked In';
                $time = null;
            } else {
                $status = 'offline';
                $task = null;
                $time = null;
            }

            return [
                'id'          => $emp->id,
                'name'        => $name,
                'role'        => $emp->role ?? 'employee',
                'avatar'      => $emp->profile_photo_path,
                'status'      => $status,
                'currentTask' => $task,
                'jobTime'     => $time,
            ];
        });

        $onJob     = $members->where('status', 'on_job')->count();
        $onRoute   = $members->where('status', 'on_route')->count();
        $clockedIn = $members->where('status', 'clocked_in')->count();
        $offline   = $members->where('status', 'offline')->count();

        return [
            'summary' => [
                ['label' => 'On Job',      'key' => 'on_job',     'count' => $onJob,     'percent' => round($onJob/$total*100).'%'],
                ['label' => 'On Route',    'key' => 'on_route',   'count' => $onRoute,   'percent' => round($onRoute/$total*100).'%'],
                ['label' => 'Clocked In',  'key' => 'clocked_in', 'count' => $clockedIn, 'percent' => round($clockedIn/$total*100).'%'],
                ['label' => 'Offline',     'key' => 'offline',    'count' => $offline,   'percent' => round($offline/$total*100).'%'],
            ],
            'members' => $members->values()->toArray(),
        ];
    }

    
    private function getTotalEarning(int $vendorId, Carbon $from, Carbon $to): array
    {
        $ef = request()->query('earning_from');
        $et = request()->query('earning_to');
        if ($ef && $et) {
            try { $from = Carbon::parse($ef)->startOfDay(); } catch (\Exception $e) {}
            try { $to   = Carbon::parse($et)->endOfDay();   } catch (\Exception $e) {}
        }
        [$pf, $pt] = $this->getPreviousRange($from, $to);
        $eids = Employee::where('vendor_id', $vendorId)->pluck('id')->toArray();

        $cur = empty($eids) ? 0 : DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.employee_id', $eids)
            ->whereBetween('invoices.created_at', [$from, $to])
            ->sum('invoice_items.final_amount');

        $prev = empty($eids) ? 0 : DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.employee_id', $eids)
            ->whereBetween('invoices.created_at', [$pf, $pt])
            ->sum('invoice_items.final_amount');

        return [
            'amount'      => number_format((float)$cur, 2),
            'change'      => $this->calcChange($cur, $prev),
            'changeLabel' => 'vs previous period',
        ];
    }

    private function getEarningChartData(int $vendorId, Carbon $from, Carbon $to): array
    {
        $ef = request()->query('earning_from');
        $et = request()->query('earning_to');
        if ($ef && $et) {
            try { $from = Carbon::parse($ef)->startOfDay(); } catch (\Exception $e) {}
            try { $to   = Carbon::parse($et)->endOfDay();   } catch (\Exception $e) {}
        }
        $eids = Employee::where('vendor_id', $vendorId)->pluck('id')->toArray();
        if (empty($eids)) return [['date' => 'No data', 'value' => 0]];

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->whereIn('invoices.employee_id', $eids)
            ->whereBetween('invoices.created_at', [$from, $to])
            ->selectRaw('DATE(invoices.created_at) as date, SUM(invoice_items.final_amount) as total')
            ->groupBy('date')->orderBy('date')
            ->get()->keyBy('date');

        if ($rows->isEmpty()) return [['date' => 'No data', 'value' => 0]];

        $result  = [];
        $current = $from->copy();
        $diff    = $from->diffInDays($to);
        $step    = $diff > 60 ? 7 : 1;
        while ($current->lte($to)) {
            $key = $current->toDateString();
            $result[] = ['date' => $current->format('M j'), 'value' => isset($rows[$key]) ? (float)$rows[$key]->total : 0];
            $current->addDays($step);
        }
        return $result;
    }

    private function getRecentQuotes(int $vendorId, Carbon $from, Carbon $to): array
    {
        return Quote::where('vendor_id', $vendorId)
            ->whereBetween('created_at', [$from, $to])
            ->with(['customer', 'client'])
            ->latest()->limit(5)->get()
            ->map(function ($q) {
                $client = $q->customer?->name ?? $q->client?->full_name ?? $q->client_name ?? 'N/A';
                return ['id'=>$q->id, 'client'=>$client, 'amount'=>'$'.number_format((float)$q->total_amount,2), 'status'=>$q->status, 'date'=>$q->created_at?->format('M d, Y')??''];
            })->toArray();
    }

    private function getRecentInvoices(int $vendorId, Carbon $from, Carbon $to): array
    {
        $eids = Employee::where('vendor_id', $vendorId)->pluck('id')->toArray();
        if (empty($eids)) return [];
        return Invoice::whereIn('employee_id', $eids)
            ->whereBetween('created_at', [$from, $to])
            ->with('customer')->latest()->limit(5)->get()
            ->map(function ($inv) {
                $amount = DB::table('invoice_items')->where('invoice_id', $inv->id)->sum('final_amount');
                return ['id'=>$inv->id, 'number'=>'Invoice #'.$inv->invoice_number, 'client'=>$inv->customer?->name??'N/A', 'amount'=>'$'.number_format($amount,2), 'status'=>$inv->status];
            })->toArray();
    }

    private function calcChange(float $cur, float $prev): string
    {
        if ($prev == 0) return $cur > 0 ? '+100%' : '0%';
        $p = round((($cur - $prev) / $prev) * 100);
        return ($p >= 0 ? '+' : '') . $p . '%';
    }

    private function resolveDateRange(Request $request): array
    {
        $f = $request->query('from');
        $t = $request->query('to');
        if (!$f && !$t) {
            return [Carbon::parse('2000-01-01')->startOfDay(), Carbon::parse('2099-12-31')->endOfDay()];
        }
        try { $from = Carbon::parse($f)->startOfDay(); } catch (\Exception $e) { $from = Carbon::today()->startOfDay(); }
        try { $to   = Carbon::parse($t)->endOfDay();   } catch (\Exception $e) { $to   = $from->copy()->endOfDay(); }
        if ($to->lt($from)) { [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()]; }
        return [$from, $to];
    }

    private function getPreviousRange(Carbon $from, Carbon $to): array
    {
        $days  = $from->diffInDays($to) + 1;
        $prevTo   = $from->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();
        return [$prevFrom, $prevTo];
    }
}
