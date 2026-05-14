<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DoctorScheduleRequestController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ScheduleController;
use Illuminate\Support\Facades\Route;

// Public Routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Public Doctor Routes
Route::get('/doctors', [DoctorController::class, 'index']);
Route::get('/doctors/{id}', [DoctorController::class, 'show']);
Route::get('/doctors/{id}/schedule', [DoctorController::class, 'schedule']);
Route::get('/doctors/{id}/reviews', [ReviewController::class, 'doctorReviews']);
Route::get('/doctors/{doctorId}/slots', [ScheduleController::class, 'getAvailableSlots']);
Route::post('/doctors/{doctorId}/validate-slot', [ScheduleController::class, 'validateSlot']);

// Public Reviews Routes
Route::get('/reviews', [ReviewController::class, 'publicIndex']);

// Public Gallery Routes
Route::get('/gallery', [GalleryController::class, 'index']);
Route::get('/gallery/categories', [GalleryController::class, 'categories']);

// Protected Routes
Route::middleware(['auth:sanctum', 'check.active'])->group(function () {

    // Auth Routes
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    // Patient Routes
    Route::prefix('patient')->group(function () {
        Route::get('/profile', [PatientController::class, 'show']);
        Route::put('/profile', [PatientController::class, 'update']);
        Route::post('/profile', [PatientController::class, 'update']);
        Route::get('/medical-history', [PatientController::class, 'medicalHistory']);
        Route::get('/appointments', [AppointmentController::class, 'myAppointments']);
        Route::get('/prescriptions', [PrescriptionController::class, 'myPrescriptions']);
        Route::get('/reports', [ReportController::class, 'myReports']);
    });

    // Appointment Routes
    Route::prefix('appointments')->group(function () {
        Route::post('/', [AppointmentController::class, 'store']);
        Route::get('/{id}', [AppointmentController::class, 'show']);
        Route::post('/{id}/cancel', [AppointmentController::class, 'cancel']);
    });

    // Prescription Routes
    Route::prefix('prescriptions')->group(function () {
        Route::get('/{id}', [PrescriptionController::class, 'show']);
    });

    // Review Routes
    Route::post('/reviews', [ReviewController::class, 'store']);

    // Notification Routes
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

    // Doctor Routes (Doctor panel)
    Route::prefix('doctor')->group(function () {
        Route::get('/dashboard', [DoctorController::class, 'dashboard']);
        Route::get('/profile', [DoctorController::class, 'doctorProfile']);
        Route::put('/profile', [DoctorController::class, 'updateDoctorProfile']);
        Route::post('/profile', [DoctorController::class, 'updateDoctorProfile']);

        // Schedule Management
        Route::get('/schedule', [ScheduleController::class, 'getMySchedule']);
        Route::post('/leave', [ScheduleController::class, 'createLeave']);
        Route::post('/custom-timing', [ScheduleController::class, 'createCustomTiming']);
        Route::delete('/override/{id}', [ScheduleController::class, 'deleteOverride']);
        Route::post('/availability/toggle', [ScheduleController::class, 'toggleAvailability']);

        // Schedule Requests (Doctor)
        Route::get('/schedule/requests', [DoctorScheduleRequestController::class, 'myRequests']);
        Route::post('/schedule/requests', [DoctorScheduleRequestController::class, 'store']);
        Route::get('/schedule/requests/{id}', [DoctorScheduleRequestController::class, 'show']);
        Route::delete('/schedule/requests/{id}', [DoctorScheduleRequestController::class, 'cancel']);

        Route::get('/appointments', [AppointmentController::class, 'doctorAppointments']);
        Route::put('/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);
        Route::get('/patients', [AppointmentController::class, 'doctorPatients']);
        Route::get('/patients/{id}', [AppointmentController::class, 'doctorPatientDetails']);
        Route::get('/patients/{id}/medical-history', [AppointmentController::class, 'patientMedicalHistory']);
        Route::get('/prescriptions', [PrescriptionController::class, 'doctorPrescriptions']);
        Route::post('/prescriptions', [PrescriptionController::class, 'store']);

        // Doctor - Reports/Medical Records
        Route::get('/reports', [ReportController::class, 'doctorReports']);
        Route::post('/reports', [ReportController::class, 'doctorStoreReport']);
        Route::get('/reports/{id}', [ReportController::class, 'doctorShowReport']);
        Route::delete('/reports/{id}', [ReportController::class, 'doctorDeleteReport']);
    });

    // Doctor Management (Admin only)
    Route::middleware('role:admin')->group(function () {
        // Admin Dashboard
        Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/admin/analytics', [AdminController::class, 'analytics']);
        Route::get('/admin/specialties', [AdminController::class, 'specialties']);
        Route::get('/admin/settings', [AdminController::class, 'getSettings']);
        Route::put('/admin/settings', [AdminController::class, 'updateSettings']);

        // Doctor Management
        Route::post('/admin/doctors', [DoctorController::class, 'store']);
        Route::get('/admin/doctors', [DoctorController::class, 'adminIndex']);
        Route::get('/admin/doctors/{id}', [DoctorController::class, 'show']);
        Route::put('/admin/doctors/{id}', [DoctorController::class, 'update']);
        Route::delete('/admin/doctors/{id}', [DoctorController::class, 'destroy']);

        // Patient Management
        Route::get('/admin/patients', [AdminController::class, 'patients']);
        Route::get('/admin/patients/{id}', [AdminController::class, 'viewPatient']);
        Route::post('/admin/users/{id}/toggle-status', [AdminController::class, 'toggleUserStatus']);

        // Appointment Management
        Route::get('/admin/appointments', [AppointmentController::class, 'index']);
        Route::get('/admin/appointments/{id}', [AppointmentController::class, 'show']);
        Route::put('/admin/appointments/{id}/status', [AppointmentController::class, 'updateStatus']);

        // Review Management
        Route::get('/admin/reviews', [AdminController::class, 'reviews']);
        Route::put('/admin/reviews/{id}/status', [ReviewController::class, 'updateStatus']);

        // Notification Management
        Route::get('/admin/notifications', [NotificationController::class, 'adminIndex']);
        Route::post('/admin/notifications', [NotificationController::class, 'createNotification']);
        Route::put('/admin/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('/admin/notifications/{id}/delete', [NotificationController::class, 'deleteNotification']);

        // Gallery Management
        Route::get('/admin/gallery', [GalleryController::class, 'adminIndex']);
        Route::post('/admin/gallery', [GalleryController::class, 'store']);
        Route::put('/admin/gallery/{id}', [GalleryController::class, 'update']);
        Route::delete('/admin/gallery/{id}', [GalleryController::class, 'destroy']);

        // Report Management
        Route::post('/admin/reports', [ReportController::class, 'store']);

        // Doctor Schedule Management
        Route::get('/admin/schedules', [ScheduleController::class, 'adminIndex']);
        Route::post('/admin/schedules', [ScheduleController::class, 'adminSaveSchedule']);
        Route::delete('/admin/schedules/{doctorId}/{dayOfWeek}', [ScheduleController::class, 'adminDeleteSchedule']);
        Route::post('/admin/doctors/{doctorId}/block', [ScheduleController::class, 'adminBlockDoctor']);
        Route::post('/admin/doctors/{doctorId}/toggle-availability', [ScheduleController::class, 'adminToggleDoctorAvailability']);

        // Schedule Request Management (Admin)
        Route::get('/admin/schedule-requests', [DoctorScheduleRequestController::class, 'index']);
        Route::get('/admin/schedule-requests/doctors', [DoctorScheduleRequestController::class, 'doctors']);
        Route::get('/admin/schedule-requests/stats', [DoctorScheduleRequestController::class, 'stats']);
        Route::get('/admin/schedule-requests/{id}', [DoctorScheduleRequestController::class, 'show']);
        Route::put('/admin/schedule-requests/{id}/approve', [DoctorScheduleRequestController::class, 'approve']);
        Route::put('/admin/schedule-requests/{id}/reject', [DoctorScheduleRequestController::class, 'reject']);
        Route::put('/admin/schedule-requests/{id}', [DoctorScheduleRequestController::class, 'update']);
        Route::delete('/admin/schedule-requests/{id}', [DoctorScheduleRequestController::class, 'destroy']);
    });
});