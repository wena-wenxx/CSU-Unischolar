/*
  Video guides on the Help page (round 17). Recorded from the real system with fictional demo data:
  a gold arrow and a caption show each step. Files: frontend/public/tutorials/*.mp4 (no sound; captions are in the video).
  Chapters: seconds from the start -> title (clicking one jumps there).
  To re-record after the screens change, see documentation/TUTORIAL_VIDEOS.md.
*/
export const TUTORIALS = {
  student: {
    title: "Student guide",
    description: "Find a scholarship, prepare your documents, apply, and follow your application.",
    src: "/tutorials/student-guide.mp4",
    poster: "/tutorials/student-guide.jpg",
    length: "3 min 24 s",
    chapters: [
      { t: 6, title: "Sign in" },
      { t: 19, title: "Welcome guide" },
      { t: 30, title: "Your dashboard" },
      { t: 51, title: "My Documents" },
      { t: 78, title: "Find a scholarship and apply" },
      { t: 103, title: "Attach documents and submit" },
      { t: 134, title: "Follow your application" },
      { t: 158, title: "Profile, messages and help" },
      { t: 182, title: "Sign out" },
    ],
  },
  staff: {
    title: "OAS staff guide",
    description: "From a student's application to the payroll, in the order of the OAS process.",
    src: "/tutorials/staff-guide.mp4",
    poster: "/tutorials/staff-guide.jpg",
    length: "5 min 38 s",
    chapters: [
      { t: 6, title: "Sign in and dashboard" },
      { t: 32, title: "1 · Programs and their rules" },
      { t: 62, title: "AI Recommendations" },
      { t: 103, title: "2 · Review an application" },
      { t: 151, title: "Agency decision and approved lists" },
      { t: 217, title: "3 · Enrollment" },
      { t: 248, title: "Tag the grantee" },
      { t: 267, title: "Payroll and Form 6-A" },
      { t: 298, title: "Office records and sign out" },
    ],
  },
};

// Which videos each role sees (staff also see the student guide, to help students).
export const TUTORIALS_FOR = {
  student: ["student"],
  staff: ["staff", "student"],
  admin: ["staff", "student"],
};
