<?php

declare(strict_types=1);

namespace App\Content\SEO;

/**
 * The template-rendered public routes whose meta title/description are
 * editable from Admin → Page SEO. Keys (case values) are stable storage
 * keys; routeName() is the Laravel route they apply to.
 */
enum SeoRoute: string
{
    case Home = 'home';
    case Blog = 'blog';
    case Instructors = 'instructors';
    case Faqs = 'faqs';
    case Login = 'login';
    case Register = 'register';
    case BecomeInstructor = 'become_instructor';
    case ForgotPassword = 'forgot_password';

    public static function fromRouteName(?string $routeName): ?self
    {
        foreach (self::cases() as $route) {
            if ($route->routeName() === $routeName) {
                return $route;
            }
        }

        return null;
    }

    public function routeName(): string
    {
        return match ($this) {
            self::Home => 'home',
            self::Blog => 'blog.index',
            self::Instructors => 'instructors.index',
            self::Faqs => 'faqs.index',
            self::Login => 'auth.login',
            self::Register => 'auth.register',
            self::BecomeInstructor => 'instructor.apply',
            self::ForgotPassword => 'auth.password.request',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Home => 'Home page',
            self::Blog => 'Blog',
            self::Instructors => 'Instructors',
            self::Faqs => 'FAQs',
            self::Login => 'Login',
            self::Register => 'Register',
            self::BecomeInstructor => 'Become an instructor',
            self::ForgotPassword => 'Forgot password',
        };
    }

    public function path(): string
    {
        return match ($this) {
            self::Home => '/',
            self::Blog => '/blog',
            self::Instructors => '/instructors',
            self::Faqs => '/faqs',
            self::Login => '/login',
            self::Register => '/register',
            self::BecomeInstructor => '/become-instructor',
            self::ForgotPassword => '/forgot-password',
        };
    }
}
