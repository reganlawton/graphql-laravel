<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Support\Objects;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\Facades\GraphQL;
use Rebing\GraphQL\Support\Mutation;

class UpdateExampleMutationWithInputType extends Mutation
{
    /** @var array<string,string> */
    protected $attributes = [
        'name' => 'updateExample',
    ];

    public function type(): Type
    {
        return GraphQL::type('Example');
    }

    protected function rules(array $args = []): array
    {
        return [
            'test' => ['required'],
        ];
    }

    public function args(): array
    {
        return [
            'test' => [
                'name' => 'test',
                'type' => Type::string(),
            ],

            'test_with_rules' => [
                'name' => 'test',
                'type' => Type::string(),
                'rules' => ['required'],
            ],
            'test_with_rules_closure' => [
                'name' => 'test',
                'type' => Type::string(),
                'rules' => function () {
                    return ['required'];
                },
            ],

            'test_with_rules_nullable_input_object' => [
                'type' => GraphQL::type('ExampleValidationInputObject'),
                'rules' => ['nullable'],
            ],

            'test_with_rules_non_nullable_input_object' => [
                'name' => 'test',
                'type' => Type::nonNull(GraphQL::type('ExampleValidationInputObject')),
                'rules' => ['required'],
            ],

            'test_with_rules_non_nullable_list_of_non_nullable_input_object' => [
                'name' => 'test',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(GraphQL::type('ExampleValidationInputObject')))),
            ],

            'test_validation_custom_attributes' => [
                'name' => 'custom validation attributes',
                'type' => Type::string(),
                'rules' => ['required'],
            ],
        ];
    }

    public function validationAttributes(array $args = []): array
    {
        return [
            'test_validation_custom_attributes' => 'custom attribute',
        ];
    }

    /**
     * @param mixed $root
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function resolve($root, array $args): array
    {
        return [
            'test' => $args['test'],
        ];
    }
}
