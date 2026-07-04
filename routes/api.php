<?php

use App\Http\Controllers\Api\V1\Client\ClientController;
use App\Http\Controllers\Api\V1\Client\ClientAvailabilityController; // NEW: Add this line
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Customer\CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\CustomerNotificationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Customer\CustomerJobController;
use App\Http\Controllers\Api\V1\Customer\CustomerQuoteController;
use App\Http\Controllers\Api\V1\Customer\CustomerServiceRequestController;
use App\Http\Controllers\Api\V1\Customer\CustomerController;
use App\Http\Controllers\Api\V1\Employee\EmployeeAuthController;
use App\Http\Controllers\Api\V1\Employee\TimeTrackingController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Booking\OnlineBookingOptionsController;
use App\Http\Controllers\Api\V1\DeploymentController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Dispatch\ScheduleDispatchController;
use App\Http\Controllers\Api\V1\Quotes\QuoteController;
use App\Http\Controllers\Api\V1\SignedFileController;
use App\Http\Controllers\Api\V1\UploadController;
use App\Http\Controllers\Api\V1\Vendor\VendorTimeEntryController;
use App\Http\Controllers\Api\V1\Jobs\JobController;
use App\Http\Controllers\Api\V1\Schedule\ScheduleController;
use App\Http\Controllers\Api\V1\OptionsController;
use App\Http\Controllers\Api\V1\Onboarding\OnboardingController;
use App\Http\Controllers\Api\V1\Invoices\InvoiceController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Reports\ReportsController;
use App\Http\Controllers\Api\V1\AI\AIQuoteController;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceSubCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\V1\Verification\VerificationController;
use App\Services\RequestAnalyticsService;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// ============================================
// REQUEST ANALYTICS ROUTES (DEBUG/ANALYSIS)
// ============================================
Route::middleware(['api'])->group(function () {
    // Get current request analytics
    Route::get('/analytics/request', function (Request $request) {
        $analytics = app(RequestAnalyticsService::class);

        return response()->json([
            'success' => true,
            'data' => $analytics->getAnalytics(),
            'timestamp' => now()->toISOString(),
        ]);
    });



    // Get analytics for specific IP
    Route::get('/analytics/ip/{ip}', function (string $ip) {
        $request = new Request();
        $request->server->set('REMOTE_ADDR', $ip);

        $analytics = new RequestAnalyticsService($request);

        return response()->json([
            'success' => true,
            'ip' => $ip,
            'data' => $analytics->getAnalytics(),
            'timestamp' => now()->toISOString(),
        ]);
    });
});

// ============================================
// <<==================== ACTUAL API ENDPOINTS ===================>>
// ============================================

// ============================================
// PUBLIC AUTHENTICATION ROUTES
// ============================================
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('auth.refresh');

    // Password reset flow
    Route::post('password/forgot', [AuthController::class, 'forgotPassword'])->name('auth.forgot-password');
    Route::post('password/reset', [AuthController::class, 'resetPassword'])->name('auth.reset-password');
    Route::post('password/verify-token', [AuthController::class, 'verifyResetToken'])->name('auth.verify-email');
});

// ============================================
// PUBLIC BOOKING ROUTE
// ============================================
Route::prefix('public')->group(function () {
    Route::post('bookings', [PublicBookingController::class, 'store']);
    Route::get('vendors', [PublicBookingController::class, 'getVendors']);
    Route::get('service-categories', [ServiceCategoryController::class, 'index']);
    Route::get('services', [ServiceController::class, 'index']);
});


Route::post('/chat/auth', [MessageController::class, 'broadcastAuth']);

Route::prefix('employee')->group(function () {
    Route::post('login', [EmployeeAuthController::class, 'login']);
    Route::post('set-password', [EmployeeAuthController::class, 'setPassword']);
    Route::post('forgot-password', [EmployeeAuthController::class, 'forgotPassword']);
    Route::post('reset-password', [EmployeeAuthController::class, 'resetPassword']);
});

Route::prefix('customer')->group(function () {
    Route::post('login', [CustomerAuthController::class, 'login']);
    Route::post('set-password', [CustomerAuthController::class, 'setPassword']);
    Route::post('forgot-password', [CustomerAuthController::class, 'forgotPassword']);
    Route::post('reset-password', [CustomerAuthController::class, 'resetPassword']);
});

Route::middleware(['employee.jwt', 'verified.account'])->prefix('employee')->group(function () {
    Route::get('me', [EmployeeAuthController::class, 'me']);

    Route::get('dashboard', [TimeTrackingController::class, 'dashboard']);
    Route::get('listings', [TimeTrackingController::class, 'listings']);
    Route::post('check-in', [TimeTrackingController::class, 'checkIn']);
    Route::post('check-out', [TimeTrackingController::class, 'checkOut']);
    Route::post('break-start', [TimeTrackingController::class, 'breakStart']);
    Route::post('break-end', [TimeTrackingController::class, 'breakEnd']);
    Route::get('time-entries', [TimeTrackingController::class, 'timeEntries']);
    Route::put('time-entry/{id}', [TimeTrackingController::class, 'updateTimeEntry']);
});

Route::middleware(['customer.jwt'])->prefix('customer')->group(function () {
    // Customer Messaging routes
    Route::get('messages', [MessageController::class, 'getCustomerConversations']);
    Route::post('messages/send', [MessageController::class, 'sendMessage']);
    Route::post('messages/read', [MessageController::class, 'markAsRead']);
    Route::get('messages/unread-count', [MessageController::class, 'getUnreadCount']);

    Route::get('me', [CustomerAuthController::class, 'me']);
    Route::get('notifications', [CustomerNotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [CustomerNotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [CustomerNotificationController::class, 'markAllRead']);
    Route::post('profile/photo', [CustomerAuthController::class, 'uploadProfilePhoto']);
    Route::get('quotes', [CustomerQuoteController::class, 'index']);
    Route::get('quotes/{id}', [CustomerQuoteController::class, 'show']);
    Route::patch('quotes/{id}/approval', [CustomerQuoteController::class, 'updateApproval']);
    Route::post('quotes/{id}/decision', [CustomerQuoteController::class, 'decide']);
    Route::post('quotes/{id}/submit', [CustomerQuoteController::class, 'submit']);

    Route::get('service-requests', [CustomerServiceRequestController::class, 'index']);
    Route::get('service-requests/{id}', [CustomerServiceRequestController::class, 'show']);
    Route::patch('service-requests/{id}/status', [CustomerServiceRequestController::class, 'updateStatus']);

    Route::get('jobs', [CustomerJobController::class, 'index']);
    Route::get('jobs/{id}', [CustomerJobController::class, 'show']);

    Route::prefix('invoices')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Customer\CustomerInvoiceController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\V1\Customer\CustomerInvoiceController::class, 'show']);
        Route::patch('/{id}/status', [\App\Http\Controllers\Api\V1\Customer\CustomerInvoiceController::class, 'updateStatus']);
    });
});

// ============================================
// PROTECTED ROUTES - REQUIRE AUTHENTICATION
// ============================================
Route::middleware(['jwt.verify', 'verified.account'])->group(function () {

    // Authenticated Services Route for Vendors
    Route::get('services', [ServiceController::class, 'index']);

    // Service Categories Admin Routes
    Route::middleware(['role:platform_admin'])->prefix('admin/service-categories')->group(function () {
        Route::post('/', [ServiceCategoryController::class, 'store']);
        Route::put('/{id}', [ServiceCategoryController::class, 'update']);
        Route::delete('/{id}', [ServiceCategoryController::class, 'destroy']);
        Route::patch('/{id}/toggle', [ServiceCategoryController::class, 'toggle']);
    });

    // Services Admin Routes
    Route::middleware(['role:platform_admin'])->prefix('admin/services')->group(function () {
        Route::get('/', [ServiceController::class, 'adminIndex']);
        Route::post('/', [ServiceController::class, 'store']);
        Route::put('/{id}', [ServiceController::class, 'update']);
        Route::delete('/{id}', [ServiceController::class, 'destroy']);
        Route::patch('/{id}/toggle-featured', [ServiceController::class, 'toggleFeatured']);
        Route::patch('/{id}/toggle-status', [ServiceController::class, 'toggleStatus']);
    });

    // Vendor Management Admin Routes
    Route::middleware(['role:platform_admin'])->prefix('admin/vendors')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'destroy']);
        Route::patch('/{id}/toggle-status', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'toggleStatus']);
        Route::patch('/{id}/reset-password', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'resetPassword']);
        
        // Employee Management
        Route::get('/{id}/employees', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'employees']);
        Route::post('/{id}/employees', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'addEmployee']);
        Route::put('/{id}/employees/{uid}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'updateEmployee']);
        Route::delete('/{id}/employees/{uid}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'deleteEmployee']);
        Route::patch('/{id}/employees/{uid}/toggle-status', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'toggleEmployeeStatus']);
        
        // Customer Management
        Route::get('/{id}/customers', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'customers']);
        Route::post('/{id}/customers', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'addCustomer']);
        Route::put('/{id}/customers/{uid}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'updateCustomer']);
        Route::delete('/{id}/customers/{uid}', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'deleteCustomer']);
        Route::patch('/{id}/customers/{uid}/toggle-status', [\App\Http\Controllers\Api\V1\Admin\VendorManagementController::class, 'toggleCustomerStatus']);
    });

    // Global Employee Management Admin Routes
    Route::middleware(['role:platform_admin'])->prefix('admin/employees')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'destroy']);
        Route::patch('/{id}/toggle-status', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'toggleStatus']);
        Route::patch('/{id}/reset-password', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'resetPassword']);
        Route::get('/{id}/schedules', [\App\Http\Controllers\Api\V1\Admin\EmployeeManagementController::class, 'schedules']);
    });

    // Global Customer Management Admin Routes
    Route::middleware(['role:platform_admin'])->prefix('admin/customers')->group(function () {
        Route::patch('/{id}/reset-password', [\App\Http\Controllers\Api\V1\Admin\CustomerManagementController::class, 'resetPassword']);
    });

    // Vendor Messaging routes
    Route::get('/messages/conversations', [MessageController::class, 'getConversations']);
    Route::get('/messages/{customerId}', [MessageController::class, 'getMessages']);
    Route::post('/messages/send', [MessageController::class, 'sendMessage']);
    Route::post('/messages/{customerId}/read', [MessageController::class, 'markAsRead']);
    Route::get('/messages/unread-count', [MessageController::class, 'getUnreadCount']);

    Route::post('/customers', [CustomerController::class, 'store']);
    Route::post('/customers/resend-setup-link', [CustomerController::class, 'resendSetupLink']);

    Route::prefix('vendor')->group(function () {
        Route::get('time-entries', [VendorTimeEntryController::class, 'index']);
        Route::post('time-entry/{id}/approve', [VendorTimeEntryController::class, 'approve']);
        Route::post('time-entry/{id}/reject', [VendorTimeEntryController::class, 'reject']);
    });

    Route::post('/bookings', [BookingController::class, 'store']);

    Route::prefix('online-booking')->group(function () {
        Route::get('/categories', [OnlineBookingOptionsController::class, 'categories']);
        Route::get('/customers', [OnlineBookingOptionsController::class, 'customers']);
        Route::get('/employees', [OnlineBookingOptionsController::class, 'employees']);
        Route::get('/locations', [OnlineBookingOptionsController::class, 'locations']);
    });

    // ============================================
    // AI ROUTES
    // ============================================
    Route::prefix('ai')->group(function () {
        Route::post('/generate-quote', [AIQuoteController::class, 'generateQuote']);
        Route::post('/analyze-intent', [AIQuoteController::class, 'analyzeIntent']);
        Route::post('/generate-quote-conversational', [AIQuoteController::class, 'generateConversational']);
        Route::post('/generate-line-items', [AIQuoteController::class, 'generateLineItems']);
    });

    // ============================================
    // SCHEDULE & DISPATCH ROUTES
    // ============================================
    Route::prefix('schedules')->group(function () {
        Route::get('/', [ScheduleDispatchController::class, 'index']);
        Route::post('/', [ScheduleDispatchController::class, 'store']);
        Route::get('/upcoming', [ScheduleDispatchController::class, 'upcoming']);
        Route::get('/crews', [ScheduleDispatchController::class, 'crews']);
        Route::put('/{id}', [ScheduleDispatchController::class, 'update']);
        Route::delete('/{id}', [ScheduleDispatchController::class, 'destroy']);
    });

    // ============================================
    // AUTH & PROFILE ROUTES
    // ============================================
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');

        // Password management
        Route::put('password', [AuthController::class, 'updatePassword'])->name('auth.update-password');
        Route::put('password/force-change', [AuthController::class, 'forceChangePassword'])->name('auth.force-change-password');

        // Security information
        Route::get('security/info', [AuthController::class, 'getPasswordSecurityInfo'])->name('auth.security-info');
        Route::get('security/logs', [AuthController::class, 'getSecurityLogs'])->name('auth.security-logs');
    });

    // ============================================
    // VENDOR MANAGEMENT ROUTES
    // ============================================
    Route::prefix('vendors')->group(function () {
        // ============================================
        // CLIENT MANAGEMENT ROUTES
        // ============================================
        Route::prefix('clients')->group(function () {
            // Get all clients for the authenticated vendor with filters
            Route::get('/', [ClientController::class, 'getVendorClients']);
            // Get a specific client for the authenticated vendor
            Route::get('/{clientId}', [ClientController::class, 'getVendorClient']);
            // Add a new client for the authenticated vendor
            Route::post('/', [ClientController::class, 'addClient']);
            // Update a client for the authenticated vendor
            Route::put('/{clientId}', [ClientController::class, 'modifyClient']);
            // Delete a client for the authenticated vendor
            Route::delete('/{clientId}', [ClientController::class, 'removeClient']);

            // ============================================
            // CLIENT AVAILABILITY SCHEDULING ROUTES
            // ============================================
            Route::prefix('{clientId}/availability')->group(function () {
                // Get all availability schedules for client
                Route::get('/', [ClientAvailabilityController::class, 'index']);
                // Get active availability schedule
                Route::get('/active', [ClientAvailabilityController::class, 'getActive']);
                // Create new availability schedule
                Route::post('/', [ClientAvailabilityController::class, 'store']);
                // Check client availability for specific date/time
                Route::get('/check', [ClientAvailabilityController::class, 'checkAvailability']);
                // Get available time slots for specific date
                Route::get('/slots', [ClientAvailabilityController::class, 'getAvailableSlots']);
            });
        });

        // ============================================
        // EMPLOYEE MANAGEMENT ROUTES
        // ============================================
        Route::prefix('employees')->group(function () {
            // Statistics and hierarchy
            Route::get('/statistics', [EmployeeController::class, 'getStatistics']);
            Route::get('/hierarchy', [EmployeeController::class, 'getHierarchy']);

            // CRUD operations
            Route::get('/', [EmployeeController::class, 'getVendorEmployees']);
            Route::post('/', [EmployeeController::class, 'addEmployee']);
            Route::get('/{employeeId}', [EmployeeController::class, 'getVendorEmployee']);
            Route::put('/{employeeId}', [EmployeeController::class, 'modifyEmployee']);
            Route::delete('/{employeeId}', [EmployeeController::class, 'removeEmployee']);

            // Optional: Get employees by department/designation
            Route::get('/department/{department}', [EmployeeController::class, 'getByDepartment']);
            Route::get('/designation/{designation}', [EmployeeController::class, 'getByDesignation']);

            // Optional: Get subordinates of a manager
            Route::get('/{employeeId}/subordinates', [EmployeeController::class, 'getSubordinates']);
            Route::get('/managers/options', [OptionsController::class, 'managers']);
        });


        // ============================================
        // QUOTE MANAGEMENT ROUTES
        // ============================================
        Route::prefix('quotes')->group(function () {
            Route::get('/', [QuoteController::class, 'index']);
            Route::get('/statistics', [QuoteController::class, 'statistics']);
            Route::get('/number/{quoteNumber}', [QuoteController::class, 'showByNumber']);
            Route::post('/', [QuoteController::class, 'store']);
            Route::get('/{id}', [QuoteController::class, 'show']);
            Route::put('/{id}', [QuoteController::class, 'update']);
            Route::delete('/{id}', [QuoteController::class, 'destroy']);
            Route::post('/{id}/send', [QuoteController::class, 'send']);
            Route::post('/{id}/follow-up-status', [QuoteController::class, 'updateFollowUpStatus']);
            Route::post('/{id}/convert-to-job', [QuoteController::class, 'convertToJob']);
            Route::post('/{id}/accept', [QuoteController::class, 'accept']);
            Route::post('/{id}/reject', [QuoteController::class, 'reject']);
        });

        // ============================================
        // WORK ORDER MANAGEMENT ROUTES (JOBS)
        // ============================================
        Route::prefix('jobs')->group(function () {
            // Statistics
            Route::get('/statistics', [JobController::class, 'statistics']);

            // Get by work order number
            Route::get('/number/{JobNumber}', [JobController::class, 'showByNumber']);

            // CRUD operations
            Route::get('/', [JobController::class, 'index']);
            Route::post('/', [JobController::class, 'store']);
            Route::get('/{id}', [JobController::class, 'show']);
            Route::put('/{id}', [JobController::class, 'update']);
            Route::delete('/{id}', [JobController::class, 'destroy']);

            // Status update
            Route::patch('/{id}/status', [JobController::class, 'updateStatus']);

            // Task management
            Route::post('/{id}/tasks', [JobController::class, 'addTask']);
            Route::patch('/{id}/tasks/{taskId}/toggle', [JobController::class, 'toggleTask']);
            Route::delete('/{id}/tasks/{taskId}', [JobController::class, 'deleteTask']);

            // Attachment management
            Route::post('/{id}/attachments', [JobController::class, 'addAttachment']);
            Route::delete('/{id}/attachments/{attachmentId}', [JobController::class, 'deleteAttachment']);

            // Job assignment
            Route::post('/{id}/assign', [JobController::class, 'assignJob']);
        });

        // ============================================
        // INVOICE MANAGEMENT ROUTES
        // ============================================
        Route::prefix('invoices')->group(function () {
            Route::get('/', [InvoiceController::class, 'index']);
            Route::post('/', [InvoiceController::class, 'store']);
            Route::get('/{id}', [InvoiceController::class, 'show']);
            Route::put('/{id}', [InvoiceController::class, 'update']);
            Route::delete('/{id}', [InvoiceController::class, 'destroy']);
            Route::post('/{id}/send', [InvoiceController::class, 'send']);
        });

        // ============================================
        // SCHEDULE MANAGEMENT ROUTES
        // ============================================
        Route::prefix('schedules')->group(function () {
            Route::get('/', [ScheduleController::class, 'index']);
            Route::post('/', [ScheduleController::class, 'store']);
            Route::get('/{id}', [ScheduleController::class, 'show']);
            Route::put('/{id}', [ScheduleController::class, 'update']);
            Route::delete('/{id}', [ScheduleController::class, 'destroy']);
        });

        // ============================================
        // DIRECT AVAILABILITY SCHEDULE MANAGEMENT
        // (For updating/deleting specific schedules)
        // ============================================
        Route::prefix('availability-schedules')->group(function () {
            // Update specific availability schedule
            Route::put('/{scheduleId}', [ClientAvailabilityController::class, 'update']);
            // Delete specific availability schedule
            Route::delete('/{scheduleId}', [ClientAvailabilityController::class, 'destroy']);
        });

        // ============================================
        // REPORTS & ANALYTICS ROUTES
        // ============================================
        Route::prefix('reports')->group(function () {
            Route::get('/overview', [ReportsController::class, 'overview']);
        });
    });



    // ============================================
    // UPLOAD MANAGEMENT ROUTES
    // ============================================
    Route::prefix('uploads')->group(function () {
        Route::post('/temp', [UploadController::class, 'uploadTemporary']);
        Route::get('/limits', [UploadController::class, 'getUploadLimits']);
    });

    // ============================================
    // ONBOARDING MANAGEMENT ROUTES (PROTECTED)
    // ============================================
    Route::prefix('onboarding')->group(function () {
        Route::get('/templates', [OnboardingController::class, 'templates']);
        Route::post('/assign', [OnboardingController::class, 'assign']);
        Route::get('/assigned', [OnboardingController::class, 'listAssigned']);
        Route::get('/download/{id}', [OnboardingController::class, 'download']);
    });
});

// ============================================
// VERIFICATION FLOW ROUTES (PROTECTED)
// ============================================
Route::middleware(['any.jwt'])->prefix('verification')->group(function () {
    Route::get('/progress', [VerificationController::class, 'getProgress']);
    Route::post('/progress', [VerificationController::class, 'saveProgress']);
    Route::post('/document/upload', [VerificationController::class, 'uploadDocument']);
    Route::get('/document/view', [VerificationController::class, 'viewDocument']);
    Route::post('/otp/send', [VerificationController::class, 'sendOtp']);
    Route::post('/otp/verify', [VerificationController::class, 'verifyOtp']);
});

// ============================================
// SIGNED URL ROUTES
// ============================================
Route::prefix('files')->group(function () {
    // Serve signed files (no auth required - URL itself is the auth)
    Route::get('/signed/{signature}', [SignedFileController::class, 'serveSigned'])
        ->name('api.v1.files.signed');
});

// ============================================
// ONBOARDING PUBLIC ROUTES (TOKEN-BASED, NO AUTH)
// ============================================
Route::prefix('onboarding')->group(function () {
    Route::get('/{token}', [OnboardingController::class, 'getByToken']);
    Route::post('/{token}/submit', [OnboardingController::class, 'submit']);
    Route::get('/{token}/template-pdf', [OnboardingController::class, 'templatePdf']);
});

// ============================================
// DEPLOYMENT WEBHOOKS AND MANUAL DEPLOY ROUTES
// ============================================
Route::prefix('webhooks')->group(function () {
    Route::get('/github', [DeploymentController::class, 'verifyWebhook']);

    Route::post('/github', [DeploymentController::class, 'handleWebhook'])
        ->middleware(['throttle:10,1', 'github-webhook']);

    Route::post('/manual-deploy', [DeploymentController::class, 'manualDeploy'])
        ->middleware('throttle:5,1');

    // Rollback endpoint
    Route::post('/rollback', [DeploymentController::class, 'rollback'])
        ->middleware('throttle:2,10');
});

// ============================================
// FALLBACK FOR UNDEFINED ROUTES
// ============================================
Route::fallback(function () {
    return response()->json([
        'status' => 'error',
        'message' => 'Endpoint not found',
        'timestamp' => now()->toISOString()
    ], 404);
});

Route::middleware(['jwt.auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::post('/dashboard/availability', [DashboardController::class, 'toggleAvailability']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('/vendors/reports/overview', [ReportsController::class, 'overview']);
    Route::get('/vendors/reports/service-types', [ReportsController::class, 'getServiceTypes']);
});

// ============================================
// SERVICE SUB-CATEGORY ROUTES (ADMIN ONLY)
// ============================================
Route::middleware(['jwt.verify', 'role:platform_admin'])->prefix('admin/service-sub-categories')->group(function () {
    Route::get('/', [ServiceSubCategoryController::class, 'index']);
    Route::post('/', [ServiceSubCategoryController::class, 'store']);
    Route::put('/{id}', [ServiceSubCategoryController::class, 'update']);
    Route::delete('/{id}', [ServiceSubCategoryController::class, 'destroy']);
    Route::patch('/{id}/toggle', [ServiceSubCategoryController::class, 'toggle']);
});

// PUBLIC SERVICE SUB-CATEGORIES (no auth needed)
Route::prefix('service-sub-categories')->group(function () {
    Route::get('/', [ServiceSubCategoryController::class, 'index']);
});
