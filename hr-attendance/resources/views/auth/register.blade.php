<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - HR Attendance & Payroll</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; }
        .register-card { max-width: 480px; width: 100%; }
    </style>
</head>
<body>
    <div class="container">
        <div class="register-card mx-auto">
            <div class="card shadow-lg border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <h3 class="fw-bold">HR Attendance</h3>
                        <p class="text-muted">Create a new account</p>
                    </div>

                    @if($errors->any())
                        <div class="alert alert-danger">
                            @foreach($errors->all() as $error)
                                <div>{{ $error }}</div>
                            @endforeach
                        </div>
                    @endif

                    <form method="POST" action="{{ url('/register') }}">
                        @csrf
                        <div class="mb-3">
                            <label for="name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="{{ old('name') }}" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="{{ old('email') }}" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password"
                                   required minlength="6">
                            <div class="form-text">Minimum 6 characters</div>
                        </div>
                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="password_confirmation"
                                   name="password_confirmation" required minlength="6">
                        </div>

                        <div class="alert alert-info small py-2 mb-3">
                            <i class="bi bi-info-circle"></i>
                            New accounts are registered as <strong>HR Staff</strong>.
                            Contact your CEO or Admin for role upgrades.
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Create Account</button>
                    </form>

                    <div class="text-center mt-3">
                        <span class="text-muted">Already have an account?</span>
                        <a href="{{ url('/login') }}">Sign In</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
