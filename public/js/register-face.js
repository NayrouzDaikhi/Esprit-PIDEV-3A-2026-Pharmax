// Initialize Face API logic
const elImage = document.querySelector('input[type="file"]');
const elDataFaceApi = document.getElementById('dataFaceApi');
const elForm = document.querySelector('form');
const submitBtn = elForm.querySelector('button[type="submit"]');

// Load models explicitly - Use SAME models as login for compatibility
let modelsLoaded = false;
async function loadModels() {
    try {
        await faceapi.nets.tinyFaceDetector.loadFromUri('/models');
        await faceapi.nets.faceLandmark68TinyNet.loadFromUri('/models');
        await faceapi.nets.faceRecognitionNet.loadFromUri('/models');
        modelsLoaded = true;
        console.log("FaceAPI Models Loaded");
    } catch (err) {
        console.error("Error loading models:", err);
    }
}
// Start loading immediately
loadModels();

elForm.addEventListener('submit', async (e) => {
    // Check if user selected a file
    const file = elImage && elImage.files ? elImage.files[0] : null;
    
    // Check if we already processed the face data (prevent infinite loop)
    // If hidden input has value, it means we already successfully processed it.
    if (elDataFaceApi.value && file) {
        return; // Allow form to submit normally
    }

    // Only intercept if a file is selected
    if (file) {
        e.preventDefault();

        // UI Feedback
        const originalBtnText = submitBtn.innerText;
        submitBtn.disabled = true;
        submitBtn.innerText = "Processing Face Data...";

        try {
            // Wait for models if not ready
            if (!modelsLoaded) {
                submitBtn.innerText = "Loading AI Models...";
                await loadModels();
            }

            // 1. Convert file to image element
            const img = await faceapi.bufferToImage(file);
            
            // 2. Detect and compute descriptor - SAME method as login
            const detections = await faceapi.detectAllFaces(img, new faceapi.TinyFaceDetectorOptions())
                .withFaceLandmarks(true);
            
            if (detections.length > 0) {
                // Compute descriptor for the detected face
                const descriptor = await faceapi.computeFaceDescriptor(img, detections[0].detection.box);
                
                // 3. Convert to CSV string (same format as login)
                const descArray = Array.from(descriptor);
                elDataFaceApi.value = descArray.join(',');
                
                // 4. Re-submit form
                elForm.submit(); 
            } else {
                alert("No face detected! Please use a clear picture.");
                submitBtn.disabled = false;
                submitBtn.innerText = originalBtnText;
            }
        } catch (err) {
            console.error(err);
            alert("Error processing face image. " + (err.message || err));
            submitBtn.disabled = false;
            submitBtn.innerText = originalBtnText;
        }
    }
    // If no file, let the form submit normally
});
