
[← Back to Usage Guide](Getting-Started-usage) | [Home](Home)

---

## Employee Management

The Employees module allows you to manage user accounts and permissions.
        string title
        string description
        int instructor_id FK
        date created_at
    }
    Enrollment {
        int id PK
        int user_id FK
        int course_id FK
        date enrolled_at
    }
    Assignment {
        int id PK
        string title
        string description
        int course_id FK
        date due_date
    }
    Submission {
        int id PK
        int assignment_id FK
        int user_id FK
        string content
        date submitted_at
        int grade
    }

    User ||--o{ Enrollment : "enrolled in"
    Course ||--o{ Enrollment : "has"
    Course ||--o{ Assignment : "includes"
    Assignment ||--o{ Submission : "submitted by"
    User ||--o{ Submission : "created by"