<?php

namespace App\Http\Requests;

use App\Actions\Projects\CreateProject;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation of the project forms and API calls: the same rules as the
 * Action (which authorizes and validates again).
 */
class ProjectRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $record = $this->route('project');
        $rules = CreateProject::rules($record instanceof Project ? $record : null);

        if ($this->isMethod('PATCH')) {
            $rules = array_map(fn (array $rules) => ['sometimes', ...$rules], $rules);
        }

        return $rules;
    }
}
