<?php
require_once 'includes/check_login.php';
require_once 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-4xl mx-auto bg-white rounded-lg shadow-lg overflow-hidden">
        <!-- Header -->
        <div class="bg-primary-600 text-white px-6 py-4">
            <h2 class="text-2xl font-bold">Enroll New Student</h2>
            <p class="text-primary-100">Fill in the form below to register a new student</p>
        </div>
        
        <!-- Progress Steps -->
        <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
                <div class="flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full bg-primary-600 text-white flex items-center justify-center font-bold">1</div>
                    <span class="text-sm mt-1 text-gray-600">Personal Info</span>
                </div>
                <div class="h-1 flex-1 bg-gray-200 mx-2"></div>
                <div class="flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold">2</div>
                    <span class="text-sm mt-1 text-gray-400">Account Details</span>
                </div>
                <div class="h-1 flex-1 bg-gray-200 mx-2"></div>
                <div class="flex flex-col items-center">
                    <div class="w-10 h-10 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold">3</div>
                    <span class="text-sm mt-1 text-gray-400">Review & Submit</span>
                </div>
            </div>
        </div>
        
        <!-- Form -->
        <form id="enrollmentForm" class="p-6" novalidate>
            <!-- Personal Information -->
            <div id="step1">
                <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Personal Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name <span class="text-red-500">*</span></label>
                        <input type="text" id="first_name" name="first_name" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name <span class="text-red-500">*</span></label>
                        <input type="text" id="last_name" name="last_name" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" id="email" name="email" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                    <div>
                        <label for="contact_number" class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                    </div>
                    <div>
                        <label for="lrn" class="block text-sm font-medium text-gray-700 mb-1">LRN (Learner's Reference Number) <span class="text-red-500">*</span></label>
                        <input type="text" id="lrn" name="lrn" required
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                               placeholder="12-digit number">
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end">
                    <button type="button" onclick="nextStep(1, 2)" class="px-6 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Next <i class="fas fa-arrow-right ml-2"></i>
                    </button>
                </div>
            </div>
            
            <!-- Account Information -->
            <div id="step2" class="hidden">
                <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Account Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="text" id="username" name="username" required
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500 pr-10">
                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                <span id="username-availability" class="text-sm"></span>
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Choose a unique username for login</p>
                    </div>
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" id="password" name="password" required minlength="8"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <button type="button" onclick="togglePassword('password')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-600">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" id="confirm_password" name="confirm_password" required
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                            <button type="button" onclick="togglePassword('confirm_password')" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-600">
                                <i class="far fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-between">
                    <button type="button" onclick="prevStep(2, 1)" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        <i class="fas fa-arrow-left mr-2"></i> Back
                    </button>
                    <button type="button" onclick="nextStep(2, 3)" class="px-6 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Next <i class="fas fa-arrow-right ml-2"></i>
                    </button>
                </div>
            </div>
            
            <!-- Review & Submit -->
            <div id="step3" class="hidden">
                <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">Review & Submit</h3>
                
                <div class="bg-gray-50 p-4 rounded-lg mb-6">
                    <h4 class="font-medium text-gray-900 mb-3">Personal Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Full Name</p>
                            <p id="review-name" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Email</p>
                            <p id="review-email" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Contact Number</p>
                            <p id="review-phone" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">LRN</p>
                            <p id="review-lrn" class="font-medium"></p>
                        </div>
                    </div>
                    
                    <h4 class="font-medium text-gray-900 mt-6 mb-3">Account Information</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Username</p>
                            <p id="review-username" class="font-medium"></p>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-start">
                    <div class="flex items-center h-5">
                        <input id="terms" name="terms" type="checkbox" required
                               class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="terms" class="font-medium text-gray-700">I confirm that all information provided is accurate</label>
                        <p class="text-gray-500">By checking this box, you agree to our terms and conditions.</p>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-between">
                    <button type="button" onclick="prevStep(3, 2)" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        <i class="fas fa-arrow-left mr-2"></i> Back
                    </button>
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        <i class="fas fa-check-circle mr-2"></i> Submit Enrollment
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Success Modal -->
<div id="successModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <div class="text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100">
                <i class="fas fa-check text-green-600 text-xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mt-3">Enrollment Successful!</h3>
            <div class="mt-2">
                <p class="text-sm text-gray-500">
                    The student has been successfully enrolled. A confirmation email has been sent to <span id="success-email" class="font-medium"></span>.
                </p>
            </div>
            <div class="mt-4">
                <button type="button" onclick="closeSuccessModal()" class="inline-flex justify-center px-4 py-2 text-sm font-medium text-white bg-primary-600 border border-transparent rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    Close
                </button>
                <a href="enroll_student_form.php" class="ml-3 inline-flex justify-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    Enroll Another Student
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Form navigation
function showStep(step) {
    document.querySelectorAll('[id^="step"]').forEach(div => {
        div.classList.add('hidden');
    });
    document.getElementById('step' + step).classList.remove('hidden');
}

function nextStep(current, next) {
    // Validate current step before proceeding
    if (current === 1) {
        const requiredFields = ['first_name', 'last_name', 'email', 'lrn'];
        let isValid = true;
        
        requiredFields.forEach(field => {
            const element = document.getElementById(field);
            if (!element.value.trim()) {
                element.classList.add('border-red-500');
                isValid = false;
            } else {
                element.classList.remove('border-red-500');
            }
        });
        
        // Validate email format
        const email = document.getElementById('email');
        if (email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) {
            email.classList.add('border-red-500');
            isValid = false;
        }
        
        if (!isValid) {
            alert('Please fill in all required fields correctly.');
            return;
        }
    } else if (current === 2) {
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        let isValid = true;
        
        if (!username.value) {
            username.classList.add('border-red-500');
            isValid = false;
        }
        
        if (password.value.length < 8) {
            password.classList.add('border-red-500');
            isValid = false;
        }
        
        if (password.value !== confirmPassword.value) {
            confirmPassword.classList.add('border-red-500');
            isValid = false;
        }
        
        if (!isValid) {
            alert('Please check your account information and try again.');
            return;
        }
    }
    
    // If all validations pass, show the next step
    showStep(next);
    
    // Update progress indicators
    document.querySelectorAll('.flex.flex-col.items-center').forEach((el, index) => {
        const stepNumber = index + 1;
        const indicator = el.firstElementChild;
        const text = el.lastElementChild;
        
        if (stepNumber < next) {
            indicator.className = 'w-10 h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center font-bold';
            indicator.innerHTML = '<i class="fas fa-check"></i>';
            text.className = 'text-sm mt-1 text-green-600';
        } else if (stepNumber === next) {
            indicator.className = 'w-10 h-10 rounded-full bg-primary-600 text-white flex items-center justify-center font-bold';
            indicator.textContent = stepNumber;
            text.className = 'text-sm mt-1 text-gray-600 font-medium';
        } else {
            indicator.className = 'w-10 h-10 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold';
            indicator.textContent = stepNumber;
            text.className = 'text-sm mt-1 text-gray-400';
        }
    });
    
    // If moving to review step, update the review fields
    if (next === 3) {
        document.getElementById('review-name').textContent = 
            `${document.getElementById('first_name').value} ${document.getElementById('last_name').value}`;
        document.getElementById('review-email').textContent = document.getElementById('email').value;
        document.getElementById('review-phone').textContent = document.getElementById('contact_number').value || 'N/A';
        document.getElementById('review-lrn').textContent = document.getElementById('lrn').value;
        document.getElementById('review-username').textContent = document.getElementById('username').value;
    }
}

function prevStep(current, prev) {
    showStep(prev);
    
    // Update progress indicators
    document.querySelectorAll('.flex.flex-col.items-center').forEach((el, index) => {
        const stepNumber = index + 1;
        const indicator = el.firstElementChild;
        const text = el.lastElementChild;
        
        if (stepNumber < current) {
            indicator.className = 'w-10 h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center font-bold';
            indicator.innerHTML = '<i class="fas fa-check"></i>';
            text.className = 'text-sm mt-1 text-green-600';
        } else if (stepNumber === current) {
            indicator.className = 'w-10 h-10 rounded-full bg-primary-600 text-white flex items-center justify-center font-bold';
            indicator.textContent = stepNumber;
            text.className = 'text-sm mt-1 text-gray-600 font-medium';
        } else {
            indicator.className = 'w-10 h-10 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold';
            indicator.textContent = stepNumber;
            text.className = 'text-sm mt-1 text-gray-400';
        }
    });
}

// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const icon = field.nextElementSibling.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Username availability check
let usernameCheckTimeout;
const usernameInput = document.getElementById('username');
const usernameAvailability = document.getElementById('username-availability');

if (usernameInput) {
    usernameInput.addEventListener('input', function() {
        clearTimeout(usernameCheckTimeout);
        const username = this.value.trim();
        
        if (username.length < 3) {
            usernameAvailability.textContent = '';
            return;
        }
        
        usernameAvailability.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        usernameCheckTimeout = setTimeout(() => {
            // Simulate API call to check username availability
            // In a real app, you would make an AJAX call to your server
            setTimeout(() => {
                const isAvailable = Math.random() > 0.5; // Simulate random availability
                
                if (isAvailable) {
                    usernameAvailability.innerHTML = '<i class="fas fa-check text-green-500"></i>';
                    usernameInput.classList.remove('border-red-500');
                    usernameInput.classList.add('border-green-500');
                } else {
                    usernameAvailability.innerHTML = '<i class="fas fa-times text-red-500"></i>';
                    usernameInput.classList.remove('border-green-500');
                    usernameInput.classList.add('border-red-500');
                }
            }, 500);
        }, 500);
    });
}

// Form submission
document.getElementById('enrollmentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Validate terms checkbox
    if (!document.getElementById('terms').checked) {
        alert('Please confirm that all information is accurate by checking the box.');
        return;
    }
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalBtnText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
    
    // Prepare form data
    const formData = new FormData(this);
    
    // Submit form data via AJAX
    fetch('enroll_student.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success modal
            document.getElementById('success-email').textContent = document.getElementById('email').value;
            document.getElementById('successModal').classList.remove('hidden');
            
            // Reset form
            this.reset();
            showStep(1);
            
            // Reset progress indicators
            document.querySelectorAll('.flex.flex-col.items-center').forEach((el, index) => {
                const indicator = el.firstElementChild;
                const text = el.lastElementChild;
                
                if (index === 0) {
                    indicator.className = 'w-10 h-10 rounded-full bg-primary-600 text-white flex items-center justify-center font-bold';
                    text.className = 'text-sm mt-1 text-gray-600';
                } else {
                    indicator.className = 'w-10 h-10 rounded-full bg-gray-200 text-gray-500 flex items-center justify-center font-bold';
                    text.className = 'text-sm mt-1 text-gray-400';
                }
                indicator.textContent = index + 1;
            });
        } else {
            alert('Error: ' + (data.message || 'An unknown error occurred'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while processing your request. Please try again.');
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalBtnText;
    });
});

// Close success modal
function closeSuccessModal() {
    document.getElementById('successModal').classList.add('hidden');
}

// Initialize the form
showStep(1);
</script>

<?php require_once 'includes/footer.php'; ?>
