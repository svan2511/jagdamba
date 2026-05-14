# Hospital API - Backend

## Project Overview
- **Name**: MAA JAGDAMBA SUPER SPECIALITY HOSPITAL - API
- **Type**: Laravel 11 REST API
- **Framework**: Laravel 11 with Sanctum for authentication
- **Database**: MySQL (via Eloquent ORM)
- **Purpose**: Hospital management system with role-based access

## Roles
1. **Patient** - Book appointments, view records, prescriptions
2. **Doctor** - Manage appointments, write prescriptions, patient history
3. **Admin** - Full system management (doctors, patients, appointments, CMS)

## Database Schema

### Tables
- `users` - Auth users (patients, doctors, admins)
- `doctors` - Doctor profiles (specialty, qualification, schedule, etc.)
- `patients` - Patient profiles (medical history, allergies, emergency contact)
- `appointments` - Appointment bookings with status tracking
- `prescriptions` - Doctor prescriptions for patients
- `medical_records` - Patient medical history
- `reports` - Lab reports, diagnostic reports
- `notifications` - System notifications for users
- `reviews` - Doctor reviews/ratings
- `gallery` - Hospital images and videos
- `settings` - System settings

## API Endpoints

### Public
- `GET /api/doctors` - List all doctors (with filters)
- `GET /api/doctors/{id}` - Doctor details
- `GET /api/doctors/{id}/schedule` - Doctor availability
- `GET /api/doctors/{id}/reviews` - Doctor reviews
- `GET /api/gallery` - Public gallery

### Auth (Sanctum)
- `POST /api/auth/register` - Patient registration
- `POST /api/auth/login` - Login (returns token)
- `POST /api/auth/logout` - Logout
- `GET /api/auth/user` - Current user info
- `POST /api/auth/forgot-password` - Request password reset
- `POST /api/auth/verify-otp` - Verify OTP code
- `POST /api/auth/reset-password` - Reset with OTP

### Patient (requires auth)
- `GET /api/patient/profile` - Get profile
- `PUT /api/patient/profile` - Update profile
- `GET /api/patient/medical-history` - Medical records
- `GET /api/patient/appointments` - My appointments
- `POST /api/patient/appointments` - Book appointment
- `PUT /api/patient/appointments/{id}/cancel` - Cancel appointment
- `GET /api/patient/prescriptions` - My prescriptions
- `GET /api/patient/notifications` - My notifications
- `POST /api/doctors/{id}/reviews` - Rate doctor

### Doctor (requires auth)
- `GET /api/doctor/profile` - Get profile
- `PUT /api/doctor/profile` - Update profile
- `GET /api/doctor/appointments` - My appointments
- `PUT /api/doctor/appointments/{id}/status` - Update status
- `GET /api/doctor/patients` - My patients
- `POST /api/doctor/prescriptions` - Write prescription
- `GET /api/doctor/prescriptions` - My prescriptions
- `PUT /api/doctor/availability` - Update schedule

### Admin (requires admin role)
- `GET /api/admin/dashboard/stats` - Dashboard statistics
- `GET /api/admin/doctors` - All doctors
- `POST /api/admin/doctors` - Create doctor
- `PUT /api/admin/doctors/{id}` - Update doctor
- `DELETE /api/admin/doctors/{id}` - Delete doctor
- `GET /api/admin/patients` - All patients
- `GET /api/admin/patients/{id}` - View patient
- `GET /api/admin/appointments` - All appointments
- `PUT /api/admin/appointments/{id}` - Update appointment
- `GET /api/admin/reviews` - All reviews
- `PUT /api/admin/reviews/{id}/approve` - Approve review
- `PUT /api/admin/reviews/{id}/reject` - Reject review
- `GET /api/admin/gallery` - Gallery items
- `POST /api/admin/gallery` - Upload media
- `DELETE /api/admin/gallery/{id}` - Delete media
- `GET /api/admin/settings` - System settings
- `PUT /api/admin/settings` - Update settings
- `GET /api/admin/cms/homepage` - Homepage content
- `PUT /api/admin/cms/homepage` - Update homepage

## Project Structure

```
hospital-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/    # API Controllers
│   │   ├── Middleware/         # Auth middleware
│   │   └── Requests/           # Form requests with validation
│   ├── Models/                 # Eloquent models
│   └── Providers/              # Service providers
├── database/
│   └── migrations/            # Database migrations
├── routes/
│   └── api.php                # API routes
├── config/                     # Laravel config
└── storage/
    └── logs/                   # Laravel logs
```

## Key Controllers
- `AuthController` - Login, register, logout, password reset
- `DoctorController` - Doctor CRUD, schedule, reviews
- `PatientController` - Patient profile, medical history
- `AppointmentController` - Appointment booking, management
- `PrescriptionController` - Prescriptions CRUD
- `AdminController` - Admin dashboard, management
- `GalleryController` - Media management
- `ReviewController` - Reviews management

## Authentication
- Laravel Sanctum for token-based auth
- Tokens stored in `personal_access_tokens` table
- Bearer token in Authorization header
- Role check middleware: `role:admin`, `role:doctor`, `role:patient`

## Important Files

### Models
- `User.php` - Base user with role, name, email, phone
- `Doctor.php` - Doctor profile with specialty, schedule
- `Patient.php` - Patient profile with medical info
- `Appointment.php` - Links patient, doctor, date, status
- `Prescription.php` - Doctor's prescription for patient

### Middleware
- `auth:sanctum` - Validates token
- `role` - Checks user role

### Requests
- `CreateDoctorRequest` - Validation for creating doctors
- `AppointmentRequest` - Appointment validation

## Common Issues
- CORS - configured in bootstrap/app.php
- Token expiry - Sanctum configured
- Error logging - uses Laravel Log facade

## Commands
```bash
composer install          # Install dependencies
php artisan migrate       # Run migrations
php artisan serve         # Start server (localhost:8000)
php artisan migrate:fresh # Fresh database
php artisan make:model    # Create model
php artisan make:controller # Create controller
```

## Environment
- PHP 8.2+
- Composer
- MySQL
- Laravel 11