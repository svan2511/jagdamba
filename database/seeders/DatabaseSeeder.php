<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create Admin
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@hospital.com',
            'password' => Hash::make('admin123'),
            'phone' => '+91 8954660008',
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create Doctors
        // $doctorsData = [
        //     ['name' => 'Dr. Emily Chen', 'email' => 'emily.chen@hospital.com', 'specialty' => 'Cardiology', 'qualification' => 'MD, FACC', 'experience' => 15, 'fee' => 500],
        //     ['name' => 'Dr. Michael Park', 'email' => 'michael.park@hospital.com', 'specialty' => 'Dermatology', 'qualification' => 'MD, FAAD', 'experience' => 12, 'fee' => 400],
        //     ['name' => 'Dr. Sarah Johnson', 'email' => 'sarah.johnson@hospital.com', 'specialty' => 'General Medicine', 'qualification' => 'MD, MBBS', 'experience' => 18, 'fee' => 300],
        //     ['name' => 'Dr. Robert Lee', 'email' => 'robert.lee@hospital.com', 'specialty' => 'Orthopedics', 'qualification' => 'MD, FACS', 'experience' => 10, 'fee' => 600],
        //     ['name' => 'Dr. Priya Sharma', 'email' => 'priya.sharma@hospital.com', 'specialty' => 'Pediatrics', 'qualification' => 'MD, DNB', 'experience' => 14, 'fee' => 450],
        //     ['name' => 'Dr. Vikram Mehra', 'email' => 'vikram.mehra@hospital.com', 'specialty' => 'Neurology', 'qualification' => 'DM, FYN', 'experience' => 16, 'fee' => 700],
        // ];

        // foreach ($doctorsData as $doc) {
        //     $user = User::create([
        //         'name' => $doc['name'],
        //         'email' => $doc['email'],
        //         'password' => Hash::make('doctor123'),
        //         'phone' => '+91 9876543' . rand(100, 999),
        //         'role' => 'doctor',
        //         'is_active' => true,
        //     ]);

        //     Doctor::create([
        //         'user_id' => $user->id,
        //         'specialty' => $doc['specialty'],
        //         'qualification' => $doc['qualification'],
        //         'experience_years' => $doc['experience'],
        //         'bio' => "Experienced {$doc['specialty']} specialist with {$doc['experience']} years of practice.",
        //         'consultation_fee' => $doc['fee'],
        //         'available_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']),
        //         'start_time' => '09:00',
        //         'end_time' => '17:00',
        //         'is_available' => true,
        //         'is_verified' => true,
        //     ]);
        // }

        // // Create Patients
        // $patientsData = [
        //     ['name' => 'Sarah Wilson', 'email' => 'sarah.wilson@email.com', 'phone' => '+91 9876543201', 'dob' => '1985-03-15', 'gender' => 'female', 'blood' => 'O+'],
        //     ['name' => 'John Smith', 'email' => 'john.smith@email.com', 'phone' => '+91 9876543202', 'dob' => '1990-07-22', 'gender' => 'male', 'blood' => 'A+'],
        //     ['name' => 'Maria Garcia', 'email' => 'maria.garcia@email.com', 'phone' => '+91 9876543203', 'dob' => '1978-11-30', 'gender' => 'female', 'blood' => 'B+'],
        // ];

        // foreach ($patientsData as $pat) {
        //     $user = User::create([
        //         'name' => $pat['name'],
        //         'email' => $pat['email'],
        //         'password' => Hash::make('patient123'),
        //         'phone' => $pat['phone'],
        //         'role' => 'patient',
        //         'is_active' => true,
        //     ]);

        //     Patient::create([
        //         'user_id' => $user->id,
        //         'date_of_birth' => $pat['dob'],
        //         'gender' => $pat['gender'],
        //         'blood_type' => $pat['blood'],
        //         'address' => '123 Main Street, City',
        //     ]);
        // }

        // // Create sample appointments
        // $doctors = Doctor::all();
        // $patients = Patient::all();

        // if ($doctors->count() > 0 && $patients->count() > 0) {
        //     // Past appointment
        //     \App\Models\Appointment::create([
        //         'patient_id' => $patients->first()->id,
        //         'doctor_id' => $doctors->first()->id,
        //         'appointment_date' => now()->subDays(5),
        //         'appointment_time' => '10:00:00',
        //         'type' => 'in-person',
        //         'status' => 'completed',
        //         'reason' => 'Regular checkup',
        //     ]);

        //     // Today's appointment
        //     \App\Models\Appointment::create([
        //         'patient_id' => $patients->first()->id,
        //         'doctor_id' => $doctors->first()->id,
        //         'appointment_date' => now(),
        //         'appointment_time' => '14:30:00',
        //         'type' => 'telehealth',
        //         'status' => 'confirmed',
        //         'reason' => 'Follow-up consultation',
        //     ]);
        // }

        $this->command->info('Sample data seeded successfully!');
        $this->command->info('Admin: admin@hospital.com / admin123');
        // $this->command->info('Doctor: emily.chen@hospital.com / doctor123');
        // $this->command->info('Patient: sarah.wilson@email.com / patient123');
    }
}