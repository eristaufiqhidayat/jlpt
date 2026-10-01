<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'phone', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /** Default yang sama dengan kolom database, supaya berlaku juga sebelum model di-refresh. */
    protected $attributes = [
        'role' => 'student',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $value = $role instanceof Role ? $role->value : $role;
            if ($this->role?->value === $value) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isSensei(): bool
    {
        return $this->role === Role::Sensei;
    }

    public function isStudent(): bool
    {
        return $this->role === Role::Student;
    }

    /** Ujian yang dibuat (sensei). */
    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'sensei_id');
    }

    /** Soal yang diinput (sensei). */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'created_by');
    }

    /** Riwayat ujian (student). */
    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class, 'student_id');
    }

    public function isDirector(): bool
    {
        return $this->role === Role::Director;
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->filter()->take(2)
            ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /** Kelas yang diikuti (peserta). */
    public function classrooms(): BelongsToMany
    {
        return $this->belongsToMany(Classroom::class, 'classroom_student', 'student_id', 'classroom_id')->withTimestamps();
    }

    public function programStages(): HasMany
    {
        return $this->hasMany(ProgramStage::class, 'student_id');
    }

    public function programDocuments(): HasMany
    {
        return $this->hasMany(ProgramDocument::class, 'student_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class, 'student_id');
    }

    public function completedMaterials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class, 'material_progress', 'student_id', 'material_id')->withPivot('completed_at');
    }

    public function inbox(): HasMany
    {
        return $this->hasMany(Message::class, 'recipient_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'student_id');
    }
}
