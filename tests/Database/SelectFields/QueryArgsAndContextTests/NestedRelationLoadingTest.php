<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Database\SelectFields\QueryArgsAndContextTests;

use Rebing\GraphQL\Tests\Support\Models\Comment;
use Rebing\GraphQL\Tests\Support\Models\Post;
use Rebing\GraphQL\Tests\Support\Models\User;
use Rebing\GraphQL\Tests\Support\Traits\SqlAssertionTrait;
use Rebing\GraphQL\Tests\TestCaseDatabase;

class NestedRelationLoadingTest extends TestCaseDatabase
{
    use SqlAssertionTrait;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('graphql.execution_middleware', [
            CustomGraphqlContextMiddleware::class,
        ]);

        $app['config']->set('graphql.schemas.default', [
            'query' => [
                UsersQuery::class,
            ],
        ]);

        $app['config']->set('graphql.types', [
            CommentType::class,
            PostType::class,
            UserType::class,
        ]);
    }

    public function testGraphqlQueryFlagOverridesCustomQueryThroughContext(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'flag' => true,
                        'user_id' => $user->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'flag' => true,
                        'post_id' => $post->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'post_id' => $post->id,
                    ]);

                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'user_id' => $user->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'flag' => true,
                        'post_id' => $post->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'post_id' => $post->id,
                    ]);
            });

        // flag on posts is ignored, overridden from GraphQL via context
        $graphql = <<<'GRAQPHQL'
{
  users(flag: false, select: true, with: true) {
    id
    name
    posts(flag: true) {
      body
      id
      title
      comments(flag: true) {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?, ?) and "posts"."flag" = ? order by "posts"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testQueryNoSelectFields(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                factory(Post::class, 2)
                    ->create([
                        'user_id' => $user->id,
                    ])
                    ->each(function (Post $post): void {
                        factory(Comment::class, 2)
                            ->create([
                                'post_id' => $post->id,
                            ]);
                    });
            });

        $graphql = <<<'GRAQPHQL'
{
  users {
    id
    name
    posts {
      body
      id
      title
      comments {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select * from "users" order by "users"."id" asc;
select * from "posts" where "posts"."user_id" = ? and "posts"."user_id" is not null order by "posts"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
select * from "posts" where "posts"."user_id" = ? and "posts"."user_id" is not null order by "posts"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[0]->id,
                                        'title' => $users[0]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[1]->id,
                                        'title' => $users[0]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[0]->posts[1]->body,
                                'id' => (string) $users[0]->posts[1]->id,
                                'title' => $users[0]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[0]->id,
                                        'title' => $users[0]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[1]->id,
                                        'title' => $users[0]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[0]->id,
                                        'title' => $users[1]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[1]->id,
                                        'title' => $users[1]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[1]->posts[1]->body,
                                'id' => (string) $users[1]->posts[1]->id,
                                'title' => $users[1]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[0]->id,
                                        'title' => $users[1]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[1]->id,
                                        'title' => $users[1]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testQuerySelect(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                factory(Post::class, 2)
                    ->create([
                        'user_id' => $user->id,
                    ])
                    ->each(function (Post $post): void {
                        factory(Comment::class, 2)
                            ->create([
                                'post_id' => $post->id,
                            ]);
                    });
            });

        $graphql = <<<'GRAQPHQL'
{
  users(select: true) {
    id
    name
    posts {
      body
      id
      title
      comments {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select * from "posts" where "posts"."user_id" = ? and "posts"."user_id" is not null order by "posts"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
select * from "posts" where "posts"."user_id" = ? and "posts"."user_id" is not null order by "posts"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
select * from "comments" where "comments"."post_id" = ? and "comments"."post_id" is not null order by "comments"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[0]->id,
                                        'title' => $users[0]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[1]->id,
                                        'title' => $users[0]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[0]->posts[1]->body,
                                'id' => (string) $users[0]->posts[1]->id,
                                'title' => $users[0]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[0]->id,
                                        'title' => $users[0]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[1]->id,
                                        'title' => $users[0]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[0]->id,
                                        'title' => $users[1]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[1]->id,
                                        'title' => $users[1]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[1]->posts[1]->body,
                                'id' => (string) $users[1]->posts[1]->id,
                                'title' => $users[1]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[0]->id,
                                        'title' => $users[1]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[1]->id,
                                        'title' => $users[1]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testQueryWith(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                factory(Post::class, 2)
                    ->create([
                        'user_id' => $user->id,
                    ])
                    ->each(function (Post $post): void {
                        factory(Comment::class, 2)
                            ->create([
                                'post_id' => $post->id,
                            ]);
                    });
            });

        $graphql = <<<'GRAQPHQL'
{
  users(with: true) {
    id
    name
    posts {
      body
      id
      title
      comments {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select * from "users" order by "users"."id" asc;
select "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?, ?) order by "posts"."id" asc;
select "comments"."body", "comments"."id", "comments"."title", "comments"."post_id" from "comments" where "comments"."post_id" in (?, ?, ?, ?) order by "comments"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[0]->id,
                                        'title' => $users[0]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[1]->id,
                                        'title' => $users[0]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[0]->posts[1]->body,
                                'id' => (string) $users[0]->posts[1]->id,
                                'title' => $users[0]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[0]->id,
                                        'title' => $users[0]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[1]->id,
                                        'title' => $users[0]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[0]->id,
                                        'title' => $users[1]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[1]->id,
                                        'title' => $users[1]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[1]->posts[1]->body,
                                'id' => (string) $users[1]->posts[1]->id,
                                'title' => $users[1]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[0]->id,
                                        'title' => $users[1]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[1]->id,
                                        'title' => $users[1]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testQuerySelectAndWith(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                factory(Post::class, 2)
                    ->create([
                        'user_id' => $user->id,
                    ])
                    ->each(function (Post $post): void {
                        factory(Comment::class, 2)
                            ->create([
                                'post_id' => $post->id,
                            ]);
                    });
            });

        $graphql = <<<'GRAQPHQL'
{
  users(select: true, with: true) {
    id
    name
    posts {
      body
      id
      title
      comments {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?, ?) order by "posts"."id" asc;
select "comments"."body", "comments"."id", "comments"."title", "comments"."post_id" from "comments" where "comments"."post_id" in (?, ?, ?, ?) order by "comments"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[0]->id,
                                        'title' => $users[0]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[1]->id,
                                        'title' => $users[0]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[0]->posts[1]->body,
                                'id' => (string) $users[0]->posts[1]->id,
                                'title' => $users[0]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[0]->id,
                                        'title' => $users[0]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[1]->comments[1]->id,
                                        'title' => $users[0]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[0]->id,
                                        'title' => $users[1]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[1]->id,
                                        'title' => $users[1]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                            [
                                'body' => $users[1]->posts[1]->body,
                                'id' => (string) $users[1]->posts[1]->id,
                                'title' => $users[1]->posts[1]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[1]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[0]->id,
                                        'title' => $users[1]->posts[1]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[1]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[1]->comments[1]->id,
                                        'title' => $users[1]->posts[1]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testQuerySelectAndWithAndSubArgs(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'flag' => true,
                        'user_id' => $user->id,
                    ]);
                factory(Comment::class, 2)
                    ->create([
                        'post_id' => $post->id,
                    ]);

                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'user_id' => $user->id,
                    ]);
                factory(Comment::class, 2)
                    ->create([
                        'post_id' => $post->id,
                    ]);
            });

        $graphql = <<<'GRAQPHQL'
{
  users(select: true, with: true) {
    id
    name
    posts(flag: true) {
      body
      id
      title
      comments {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?, ?) and "posts"."flag" = ? order by "posts"."id" asc;
select "comments"."body", "comments"."id", "comments"."title", "comments"."post_id" from "comments" where "comments"."post_id" in (?, ?) order by "comments"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[0]->id,
                                        'title' => $users[0]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[0]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[1]->id,
                                        'title' => $users[0]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[0]->id,
                                        'title' => $users[1]->posts[0]->comments[0]->title,
                                    ],
                                    [
                                        'body' => $users[1]->posts[0]->comments[1]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[1]->id,
                                        'title' => $users[1]->posts[0]->comments[1]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testQuerySelectAndWithAndNestedSubArgs(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'flag' => true,
                        'user_id' => $user->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'flag' => true,
                        'post_id' => $post->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'post_id' => $post->id,
                    ]);

                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'user_id' => $user->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'flag' => true,
                        'post_id' => $post->id,
                    ]);
                factory(Comment::class)
                    ->create([
                        'post_id' => $post->id,
                    ]);
            });

        $graphql = <<<'GRAQPHQL'
{
  users(select: true, with: true) {
    id
    name
    posts(flag: true) {
      body
      id
      title
      comments(flag: true) {
        body
        id
        title
      }
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?, ?) and "posts"."flag" = ? order by "posts"."id" asc;
select "comments"."body", "comments"."id", "comments"."title", "comments"."post_id" from "comments" where "comments"."post_id" in (?, ?) and "comments"."flag" = ? order by "comments"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'posts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[0]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[0]->posts[0]->comments[0]->id,
                                        'title' => $users[0]->posts[0]->comments[0]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'posts' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                                'comments' => [
                                    [
                                        'body' => $users[1]->posts[0]->comments[0]->body,
                                        'id' => (string) $users[1]->posts[0]->comments[0]->id,
                                        'title' => $users[1]->posts[0]->comments[0]->title,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }

    public function testRelationshipAlias(): void
    {
        $users = factory(User::class, 1)
            ->create()
            ->each(function (User $user): void {
                factory(Post::class)
                    ->create([
                        'flag' => true,
                        'user_id' => $user->id,
                    ]);
            });

        $graphql = <<<'GRAQPHQL'
{
  users(select: true, with: true) {
    id
    name
    flaggedPosts {
      body
      id
      title

    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?) and "posts"."flag" = ? order by "posts"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'flaggedPosts' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame($expectedResult, $result);
    }

    public function testCustomQueryAllowColumnToBeAdded(): void
    {
        /** @var User[] $users */
        $users = factory(User::class, 2)
            ->create()
            ->each(function (User $user): void {
                /** @var Post $post */
                $post = factory(Post::class)
                    ->create([
                        'flag' => true,
                        'user_id' => $user->id,
                    ]);
            });

        $graphql = <<<'GRAQPHQL'
{
  users(select: true, with: true) {
    id
    name
    postsWithExtraField {
      body
      id
      title
    }
  }
}
GRAQPHQL;

        $this->sqlCounterReset();

        $result = $this->httpGraphql($graphql);

        $this->assertSqlQueries(
            <<<'SQL'
select "users"."id", "users"."name" from "users" order by "users"."id" asc;
select 42 as meaning_of_life, "posts"."body", "posts"."id", "posts"."title", "posts"."user_id" from "posts" where "posts"."user_id" in (?, ?) order by "posts"."id" asc;
SQL
        );

        $expectedResult = [
            'data' => [
                'users' => [
                    [
                        'id' => (string) $users[0]->id,
                        'name' => $users[0]->name,
                        'postsWithExtraField' => [
                            [
                                'body' => $users[0]->posts[0]->body,
                                'id' => (string) $users[0]->posts[0]->id,
                                'title' => $users[0]->posts[0]->title,
                            ],
                        ],
                    ],
                    [
                        'id' => (string) $users[1]->id,
                        'name' => $users[1]->name,
                        'postsWithExtraField' => [
                            [
                                'body' => $users[1]->posts[0]->body,
                                'id' => (string) $users[1]->posts[0]->id,
                                'title' => $users[1]->posts[0]->title,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame($expectedResult, $result);
    }
}
