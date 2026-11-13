{{-- Hidden POST forms for meetings/_google-status buttons. Render once per page, OUTSIDE any other <form>. $return: 'meetings' | 'settings'. --}}
@foreach(['test' => 'google.test', 'sync' => 'google.sync', 'disconnect' => 'google.disconnect'] as $action => $routeName)
    <form id="gcal-{{ $action }}-{{ $return }}" action="{{ route($routeName) }}" method="POST" class="hidden">
        @csrf
        <input type="hidden" name="return" value="{{ $return }}">
    </form>
@endforeach
