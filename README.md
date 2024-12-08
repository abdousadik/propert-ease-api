# PropertEase API

PropertEase API is a backend API designed for managing real estate projects. It provides robust functionalities for user authentication, project creation, updates, search, and secure JWT-based route access. Built with Symfony, it ensures scalability and maintainability.

## Features

- **User Authentication**: Registration and login with secure JWT tokens.
- **Project Management**:
  - Create new real estate projects.
  - Update existing projects (excluding the project title).
  - Delete projects (mark as inactive).
  - Retrieve a list of all projects.
  - Search projects by title and delivery date range.
  - View detailed information for a specific project by ID.
- **Secure Routes**: Only accessible to authenticated users.

## Installation

### Prerequisites

- PHP 8.1 or higher
- Composer
- Symfony CLI (optional but recommended)

### Steps

1. Clone the repository:

   ```bash
   git clone https://github.com/abdousadik/propretease-api.git
   cd propretease-api
   ```

2. Install dependencies:

   ```bash
   composer install
   ```

3. Set up environment variables:
   Copy the `.env` file and adjust database configuration if needed.

   ```bash
   cp .env .env.local
   ```

4. Run database migrations:

   ```bash
   php bin/console doctrine:migrations:migrate
   ```

5. Generate JWT Keys

   Generate the public & private keys for JWT:

   ```bash
   php bin/console lexik:jwt:generate-keypair
   ```

6. Start the Symfony development server:
   ```bash
   symfony server:start
   ```

## Endpoints

### Authentication

- **Register**: `POST /signup`
  ```json
  {
    "firstName": "John",
    "lastName": "Doe",
    "phone": "123456789",
    "email": "john.doe@example.com",
    "password": "securePassword"
  }
  ```
- **Login**: `POST /login_check`
  ```json
  {
    "username": "john.doe@example.com",
    "password": "securePassword"
  }
  ```

### Project Management

- **Create Project**: `POST /project`
- **Update Project**: `PATCH /project/{id}`
- **Delete Project**: `DELETE /project/{id}`
- **List Projects**: `GET /project`
- **View Project by ID**: `GET /project/{id}`
- **Search Projects**: `GET /project/search`
  - Parameters: `name`, `deliveryDateMin`, `deliveryDateMax`

## Security

This API uses JWT for securing endpoints. Ensure you include the token in the `Authorization` header for protected routes:

```
Authorization: Bearer <your-jwt-token>
```

## Postman Collection

A Postman collection file is included for testing the API. You can find it at `collection/PropertEaseAPI.postman_collection`.

## License

This project is open-source and available under the [MIT License](LICENSE).
