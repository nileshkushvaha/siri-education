{{--
    Shared "Start Your Application" call to action. Included from both
    the hero and closing sections of instructor-apply/show.blade.php.
    Relies on $existingApplication / $eligibility from
    InstructorApplicationController::show() — never queries anything
    itself.
--}}
@php $size ??= 'md'; @endphp

@auth
    @if($existingApplication)
        <x-ui.button href="{{ route('dashboard.instructor.onboarding') }}" size="{{ $size }}">
            Continue Your Application
        </x-ui.button>
    @elseif($eligibility && $eligibility->eligible)
        <form method="POST" action="{{ route('dashboard.instructor.start') }}">
            @csrf
            <x-ui.button type="submit" size="{{ $size }}">
                Start Your Application
            </x-ui.button>
        </form>
    @else
        <x-ui.button size="{{ $size }}" disabled>
            Applications Currently Unavailable
        </x-ui.button>
    @endif
@else
    <x-ui.button href="{{ route('auth.register', ['intent' => 'instructor']) }}" size="{{ $size }}">
        Start Your Application
    </x-ui.button>
@endauth
