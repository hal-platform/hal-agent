<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Application;

trait APIErrorTrait
{
    /**
     * @var array
     */
    private $errors;

    /**
     * @return array
     */
    public function errors(): array
    {
        if ($this->errors === null) {
            $this->resetErrors();
        }

        return $this->errors;
    }

    /**
     * @return array
     */
    public function apiErrors(): array
    {
        if ($this->errors === null) {
            $this->resetErrors();
        }

        $combined = [];
        foreach ($this->errors as $field => $errors) {
            $prefix = $field === 'all' ? '' : "[${field}] ";
            foreach ($errors as $error) {
                $combined[] = $prefix . $error;
            }
        }

        return $combined;
    }

    /**
     * @param string $field
     *
     * @return array
     */
    public function errorsFor($field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * @return void
     */
    private function resetErrors(): void
    {
        $this->errors = [];
    }

    /**
     * @return bool
     */
    private function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * @param string $msg
     * @param string|null $field
     *
     * @return void
     */
    private function addError(string $msg, string $field = null): void
    {
        if (!$field) {
            $field = 'all';
        }

        if (!($this->errors[$field] ?? [])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $msg;
    }

    /**
     * @param array $errors
     *
     * @return void
     */
    private function importErrors(array $errors): void
    {
        foreach ($errors as $field => $errors) {
            foreach ($errors as $message) {
                $this->addError($message, $field);
            }
        }
    }
}
