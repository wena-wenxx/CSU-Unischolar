export const APP_STATUS = {
  draft: { label: 'Draft (not submitted)', color: 'bg-gray-100 text-gray-700' },
  submitted: { label: 'Submitted', color: 'bg-blue-100 text-blue-800' },
  under_review: { label: 'Under review', color: 'bg-yellow-100 text-yellow-800' },
  needs_action: { label: 'Needs action', color: 'bg-orange-100 text-orange-800' },
  complete: { label: 'Complete', color: 'bg-teal-100 text-teal-800' },
  approved: { label: 'Approved (by agency)', color: 'bg-green-100 text-green-800' },
  rejected: { label: 'Rejected', color: 'bg-red-100 text-red-800' },
};

export const DOC_STATUS = {
  uploaded: { label: 'Uploaded', color: 'bg-gray-100 text-gray-700' },
  validated: { label: 'AI: no issues found', color: 'bg-green-100 text-green-800' },
  flagged: { label: 'AI flagged', color: 'bg-red-100 text-red-800' },
  needs_review: { label: 'Needs manual review', color: 'bg-orange-100 text-orange-800' },
};

export const SCHOLARSHIP_STATUS = ['active', 'inactive', 'closed'];
export const REVIEW_STATUSES = ['under_review', 'needs_action', 'complete', 'approved', 'rejected'];
