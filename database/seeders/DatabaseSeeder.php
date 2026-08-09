<?php

namespace Database\Seeders;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\SchemaService;
use App\Services\SubmissionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data that exercises the whole product end to end.
 *
 * After migrate:fresh --seed the reviewer can sign in, open the builder, follow
 * the public link, read the submissions with search and filtering, export the
 * CSV, and roll the schema back. That requires a form that is actually
 * published and actually has answers, so both are seeded here.
 */
class DatabaseSeeder extends Seeder
{
    public function run(SchemaService $schemas, SubmissionService $submissions): void
    {
        $user = User::query()->create([
            'name' => 'Demo User',
            'email' => 'demo@formforge.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        // Built through the service so the seed can never drift from the shape
        // the validator enforces.
        $schema = $schemas->normalize($this->demoSchema());

        $form = Form::query()->create([
            'user_id' => $user->id,
            'title' => $schema['title'],
            'description' => $schema['description'],
            'slug' => 'demo-form',
            'public_token' => (string) Str::uuid(),
            'status' => FormStatus::Published,
            'schema' => $schema,
            'schema_version' => 1,
            'published_at' => now(),
        ]);

        FormVersion::query()->create([
            'form_id' => $form->id,
            'version' => 1,
            'schema' => $schema,
            'note' => 'Initial version',
            'created_by' => $user->id,
            'created_at' => now(),
        ]);

        foreach ($this->demoAnswers() as $answers) {
            $submissions->create($form, $schema, $answers, []);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function demoSchema(): array
    {
        return [
            'schema_version' => 1,
            'title' => 'Demo Form',
            'description' => 'A sample onboarding form covering most of the supported field types.',
            'settings' => [
                'multi_step' => false,
                'submit_button_text' => 'Submit application',
                'success_message' => 'Thanks, we have received your details.',
            ],
            'sections' => [
                [
                    'title' => 'About you',
                    'description' => 'Basic contact details.',
                    'fields' => [
                        ['type' => 'heading', 'key' => 'about_you', 'label' => 'Tell us who you are'],
                        ['type' => 'text', 'key' => 'full_name', 'label' => 'Full Name', 'required' => true, 'placeholder' => 'Ada Lovelace'],
                        ['type' => 'email', 'key' => 'work_email', 'label' => 'Work Email', 'required' => true],
                        ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone Number'],
                        ['type' => 'date', 'key' => 'start_date', 'label' => 'Preferred Start Date'],
                    ],
                ],
                [
                    'title' => 'Your role',
                    'description' => null,
                    'fields' => [
                        [
                            'type' => 'dropdown',
                            'key' => 'department',
                            'label' => 'Department',
                            'required' => true,
                            'options' => [
                                ['value' => 'engineering', 'label' => 'Engineering'],
                                ['value' => 'design', 'label' => 'Design'],
                                ['value' => 'support', 'label' => 'Customer Support'],
                            ],
                        ],
                        [
                            'type' => 'radio',
                            'key' => 'employment_type',
                            'label' => 'Employment Type',
                            'options' => [
                                ['value' => 'full_time', 'label' => 'Full time'],
                                ['value' => 'part_time', 'label' => 'Part time'],
                            ],
                        ],
                        [
                            'type' => 'checkbox',
                            'key' => 'equipment',
                            'label' => 'Equipment Needed',
                            'options' => [
                                ['value' => 'laptop', 'label' => 'Laptop'],
                                ['value' => 'monitor', 'label' => 'Monitor'],
                                ['value' => 'headset', 'label' => 'Headset'],
                            ],
                        ],
                        ['type' => 'number', 'key' => 'years_experience', 'label' => 'Years of Experience', 'validation' => ['min' => 0, 'max' => 50]],
                        ['type' => 'rating', 'key' => 'confidence', 'label' => 'Confidence With Our Stack'],
                        ['type' => 'textarea', 'key' => 'notes', 'label' => 'Anything Else', 'help_text' => 'Optional, up to a short paragraph.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Answers keyed by field key, shaped the way the public renderer submits them.
     *
     * Deliberately varied so the submission search returns different subsets
     * for different terms.
     *
     * @return list<array<string, mixed>>
     */
    private function demoAnswers(): array
    {
        return [
            [
                'full_name' => 'Ada Lovelace',
                'work_email' => 'ada@example.com',
                'phone' => '+44 20 7946 0958',
                'start_date' => '2026-09-01',
                'department' => 'engineering',
                'employment_type' => 'full_time',
                'equipment' => ['laptop', 'monitor'],
                'years_experience' => 12,
                'confidence' => 5,
                'notes' => 'Happy to start earlier if needed.',
            ],
            [
                'full_name' => 'Grace Hopper',
                'work_email' => 'grace@example.com',
                'phone' => '+1 202 555 0143',
                'start_date' => '2026-09-15',
                'department' => 'engineering',
                'employment_type' => 'full_time',
                'equipment' => ['laptop'],
                'years_experience' => 20,
                'confidence' => 5,
                'notes' => 'Prefers a standing desk.',
            ],
            [
                'full_name' => 'Katherine Johnson',
                'work_email' => 'katherine@example.com',
                'phone' => '+1 757 555 0177',
                'start_date' => '2026-10-01',
                'department' => 'design',
                'employment_type' => 'part_time',
                'equipment' => ['monitor', 'headset'],
                'years_experience' => 8,
                'confidence' => 4,
                'notes' => '',
            ],
            [
                'full_name' => 'Alan Turing',
                'work_email' => 'alan@example.com',
                'phone' => '',
                'start_date' => '2026-09-08',
                'department' => 'support',
                'employment_type' => 'full_time',
                'equipment' => ['headset'],
                'years_experience' => 3,
                'confidence' => 3,
                'notes' => 'Available for weekend cover.',
            ],
            [
                'full_name' => 'Radia Perlman',
                'work_email' => 'radia@example.com',
                'phone' => '+1 617 555 0110',
                'start_date' => '2026-11-02',
                'department' => 'engineering',
                'employment_type' => 'part_time',
                'equipment' => [],
                'years_experience' => 15,
                'confidence' => 4,
                'notes' => 'Has own equipment.',
            ],
            [
                'full_name' => 'Margaret Hamilton',
                'work_email' => 'margaret@example.com',
                'phone' => '+1 617 555 0198',
                'start_date' => '2026-09-22',
                'department' => 'design',
                'employment_type' => 'full_time',
                'equipment' => ['laptop', 'headset'],
                'years_experience' => 18,
                'confidence' => 5,
                'notes' => 'Interested in mentoring.',
            ],
        ];
    }
}
