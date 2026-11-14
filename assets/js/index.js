// Script in Users/add.php
document.addEventListener("DOMContentLoaded", function () {
  const roleSelect = document.getElementById("role");
  const yearLevelGroup = document.getElementById("yearLevelGroup");
  const courseGroup = document.getElementById("courseGroup");
  const adviserName = document.getElementById("adviserName");

  const course = document.getElementById("course");
  const yearLevelSelect = document.getElementById("year_level");
  const adviser = document.getElementById("adviser");
  if (!roleSelect || !yearLevelGroup) {
    console.error("Required elements not found in DOM.");
    return;
  }

  roleSelect.addEventListener("change", function () {
    if (this.value === "4") {
      // Display the container block
      yearLevelGroup.style.display = "block";
      courseGroup.style.display = "block";
      adviserName.style.display = "block";
    } else {
      // Hide the container and clear selected value
      adviserName.style.display = "none";
      courseGroup.style.display = "none";
      yearLevelGroup.style.display = "none";

      yearLevelSelect.value = "";
      course.value = "";
      adviser.value = "";
    }
  });
});
