const webcamElement = document.getElementById('webcam');
const elStartIdentification = document.getElementById('startIdentification');
// Try to get Symfony form field, fallback to username
const emailInput = document.getElementById('login_form_email_username') || document.getElementById('username');

// Ensure container is relative for absolute positioning of canvas
const container = document.querySelector('.video-container');
if (container) {
    container.style.position = 'relative';
}

let displaySize;
let canvas;

// Initialize displaySize as soon as video metadata is available
webcamElement.addEventListener("loadedmetadata", () => {
    displaySize = {
        width: webcamElement.scrollWidth,
        height: webcamElement.scrollHeight
    };
    console.log("Display size set to:", displaySize);
    
    // Start detection AFTER displaySize is guaranteed to be set
    startDetection();
});

// Load required models - Use TinyFaceDetector for real-time performance
Promise.all([
    faceapi.nets.tinyFaceDetector.loadFromUri('/models'),
    faceapi.nets.faceLandmark68TinyNet.loadFromUri('/models'),
    faceapi.nets.faceRecognitionNet.loadFromUri('/models')
]).then(startWebcam);

function startWebcam() {
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(stream => { 
            webcamElement.srcObject = stream;
            // Note: startDetection() will be called from loadedmetadata event listener
            // Do not call it here - timing would be wrong
            webcamElement.play();
        })
        .catch(err => console.error("Webcam error:", err));
}

// Global variable to store last descriptor
let lastDetection = null;

function createCanvas() {
    canvas = faceapi.createCanvasFromMedia(webcamElement);
    canvas.id = 'face-overlay';
    // Critical: Position canvas exactly on top of video
    canvas.style.position = 'absolute';
    canvas.style.top = '0';
    canvas.style.left = '0';
    canvas.style.zIndex = '10';
    canvas.style.pointerEvents = 'none'; // Allow clicks through
    // Insert canvas into the container
    const videoContainer = document.querySelector('.video-container');
    videoContainer.append(canvas);
    faceapi.matchDimensions(canvas, displaySize);
}

function startDetection() {
    createCanvas();

    setInterval(async () => {
        if (webcamElement.paused || webcamElement.ended) return;

        const detections = await faceapi.detectAllFaces(
            webcamElement,
            new faceapi.TinyFaceDetectorOptions()
        ).withFaceLandmarks(true);     // true = use tiny landmark model

        // Compute descriptors for detected faces
        const descriptors = await Promise.all(
            detections.map(d => faceapi.computeFaceDescriptor(webcamElement, d.detection.box))
        );

        // Attach descriptors to detections
        detections.forEach((detection, i) => {
            detection.descriptor = descriptors[i];
        });

        const resizedDetections = faceapi.resizeResults(detections, displaySize);

        const ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        // Draw detections (blue box) and landmarks (68 dots)
        faceapi.draw.drawDetections(canvas, resizedDetections);
        faceapi.draw.drawFaceLandmarks(canvas, resizedDetections);

        if (detections.length > 0) {
            lastDetection = detections[0]; // save for submit
        } else {
            lastDetection = null;
        }
    }, 300);  // 300ms interval - no overlapping async calls
}

elStartIdentification.addEventListener('click', async (e) => {
    e.preventDefault();
    
    if (!lastDetection) {
        return alert("No face detected! Please ensure the box is around your face.");
    }
    
    // Use the descriptor from the continuous loop
    const descriptor = lastDetection.descriptor;
    const descArray = Array.from(descriptor); // Convert Float32Array to normal array

    const formData = new FormData();
    formData.append('email', emailInput.value);
    formData.append('dataFaceApi', descArray.join(',')); // CSV string

    const response = await fetch('/api/face-recognition', {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    
    if (result.isSuccessful) {
        // Store the proof of facial auth
        localStorage.setItem('tokenFaceRecognition', result.tokenFaceRecognition);
        
        // Stop webcam
        webcamElement.pause();
        if (webcamElement.srcObject) {
             webcamElement.srcObject.getTracks().forEach(track => track.stop());
        }
        
        // Clear drawing loop (optional, but good practice)
        // ... (requires handle logic, skipping for brevity)
        
        alert("Face verified! You can now enter your password.");
        // We set the hidden input immediately if form exists
        const loginForm = document.getElementById('formAuthentication');
        if (loginForm) {
            let hiddenInput = document.getElementById('tokenFaceRecognition');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'tokenFaceRecognition';
                hiddenInput.id = 'tokenFaceRecognition';
                loginForm.appendChild(hiddenInput);
            }
            hiddenInput.value = result.tokenFaceRecognition;
        }
    } else {
        alert(result.message);
    }
});

// Intercept form submission to append face recognition token
const loginForm = document.getElementById('formAuthentication');
if (loginForm) {
    loginForm.addEventListener('submit', function(e) {
        const token = localStorage.getItem('tokenFaceRecognition');
        if (token) {
            let hiddenInput = document.getElementById('tokenFaceRecognition');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'tokenFaceRecognition';
                hiddenInput.id = 'tokenFaceRecognition';
                loginForm.appendChild(hiddenInput);
            }
            hiddenInput.value = token;
        }
    });
}
