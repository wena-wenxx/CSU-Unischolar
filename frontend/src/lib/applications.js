import api, { errMsg } from "../services/api";

// Creates a DRAFT application and opens it so the student can upload documents.
export async function startApplication(scholarshipId, navigate, toast) {
  try {
    const response = await api.post("/applications", { scholarship_id: scholarshipId });

    navigate(`/student/applications/${response.data.id}`, {
      state: { justCreated: true },
    });
  } catch (err) {
    toast.error(errMsg(err, "Unable to start your application."));
  }
}
